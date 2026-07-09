<?php

declare(strict_types=1);

namespace Vortos\Deploy\Driver\Caddy;

use Vortos\Deploy\Cutover\CutoverResult;
use Vortos\Deploy\Cutover\DesiredRoute;
use Vortos\Deploy\Cutover\Edge\AssembledEdgeConfig;
use Vortos\Deploy\Cutover\Edge\EdgeConfigAssembler;
use Vortos\Deploy\Cutover\EdgeConfigGenerator;
use Vortos\Deploy\Cutover\EdgeRouterInterface;
use Vortos\Deploy\Cutover\LiveRoute;
use Vortos\Deploy\Cutover\Lock\EdgeCutoverLockInterface;
use Vortos\Deploy\Cutover\Lock\NullEdgeCutoverLock;
use Vortos\Deploy\Cutover\ReconcileResult;
use Vortos\Deploy\Cutover\State\EdgeState;
use Vortos\Deploy\Cutover\State\EdgeStateStoreInterface;
use Vortos\Deploy\Exception\CutoverFailedException;
use Vortos\Deploy\Target\ActiveColor;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

#[AsDriver('caddy')]
final class CaddyEdgeRouter implements EdgeRouterInterface
{
    private ?LiveRoute $lastLiveRoute = null;

    public function __construct(
        private readonly CaddyAdminClient $adminClient,
        private readonly EdgeConfigGenerator $configGenerator,
        private readonly EdgeStateStoreInterface $stateStore,
        private readonly DrainObserver $drainObserver,
        private readonly string $adminListen = 'localhost:2019',
        /**
         * Writes the fully-rendered cutover config to the edge's on-disk boot file — the file Caddy
         * boots from via "caddy run --config". This is what makes a cold restart (Docker daemon
         * restart, node reboot, fresh node) self-heal to the CURRENT route with no orchestration: the
         * admin /load switches live traffic in memory, and this persists that exact config so a Caddy
         * process restart reloads it verbatim instead of coming up empty or on a stale color. Null in
         * local/dev (no edge filesystem to write); wired with an SSH transport in push-mode deploys.
         */
        private readonly ?MountedConfigWriter $bootConfigWriter = null,
        /**
         * The precedence collaborator. When present AND an operator base config is configured, the
         * cutover config comes from the adapt-merge pipeline (operator's Caddyfile + framework-owned
         * upstream, behind the config firewall) instead of the from-scratch generator. Null / no base
         * config → the existing generated path, byte-for-byte (backward compatible).
         */
        private readonly ?EdgeConfigAssembler $configAssembler = null,
        /**
         * Serialises the cutover critical section for the environment so two overlapping deploys
         * cannot tear the live config or the boot file. Defaults to a no-op lock (single infra-less
         * node, deploys serialised by CI).
         */
        private readonly ?EdgeCutoverLockInterface $cutoverLock = null,
        private readonly int $cutoverLockTtlSeconds = 120,
    ) {}

    public function capabilities(): CapabilityDescriptor
    {
        return CaddyCapability::descriptor();
    }

    public function cutover(DesiredRoute $desired): CutoverResult
    {
        $lock = $this->cutoverLock ?? new NullEdgeCutoverLock();
        $token = $lock->acquire($desired->env, $this->cutoverLockTtlSeconds);
        if ($token === null) {
            throw CutoverFailedException::reloadFailed(
                'another cutover is in progress for this environment (edge lock held)',
            );
        }

        try {
            return $this->doCutover($desired);
        } finally {
            $lock->release($desired->env, $token);
        }
    }

    private function doCutover(DesiredRoute $desired): CutoverResult
    {
        $startTime = microtime(true);

        // Precedence: operator base config present → adapt-merge behind the config firewall; absent →
        // the from-scratch generator (backward compatible). Both are the single source of truth for
        // the cutover config shape and carry the host matcher + tls.automation for the domain.
        $assembled = $this->assemble($desired);
        $config = $assembled->config;

        // TLS guard: the from-scratch generator always writes an explicit tls.automation subject, so a
        // strict check is right there. The adapt-merge path may legitimately rely on a catch-all
        // policy or automatic HTTPS, which the config firewall already validated — so only the
        // generated path is checked here (a false negative would clobber a valid operator config).
        if ($desired->domain !== null
            && !$assembled->usedBaseConfig
            && !$this->configRetainsTls($config, $desired->domain)
        ) {
            throw CutoverFailedException::verifyMismatch(
                $desired->domain,
                'generated cutover config is missing the domain tls.automation policy',
            );
        }

        // Snapshot the live config BEFORE switching, so a failed verify can roll the edge back to the
        // last-known-good instead of leaving live traffic on an unverified config. Best-effort: a
        // fresh edge with no current config simply has no rollback target.
        $lastKnownGood = $this->snapshotLiveConfig();

        $this->adminClient->load($config);

        $drainResult = $this->drainObserver->awaitDrain($desired->drainDeadlineSeconds);

        if (!$drainResult->metricsReachable) {
            $this->rollback($lastKnownGood);
            throw CutoverFailedException::metricsUnreachable(
                'drain verification failed — metrics became unreachable during drain window',
            );
        }

        if (!$this->verifyLiveUpstream($desired)) {
            $this->rollback($lastKnownGood);
            throw CutoverFailedException::verifyMismatch(
                $desired->upstream->host . ':' . $desired->upstream->port,
                'current config does not match',
            );
        }

        // Only after the live switch is PROVEN do we persist durability state, so the boot file and
        // the control-plane store never record a route that failed to take. Order: routing intent
        // (edge-node reconstruction) then the exact rendered boot file (the file Caddy boots from on a
        // bare Docker daemon restart, which never re-runs edge-init).
        $this->stateStore->save(EdgeState::fromRoute($desired)->withConfigHash($assembled->finalSha256()));

        $bootJson = json_encode($config, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT);
        if ($this->bootConfigWriter !== null) {
            try {
                $this->bootConfigWriter->write($bootJson);
            } catch (\Throwable $e) {
                // Live is on the new config but the durable boot file failed to update: a restart
                // would resurrect the old route (live≠disk). Roll live back to match disk and fail
                // closed rather than leave the two diverged.
                $this->rollback($lastKnownGood);
                throw CutoverFailedException::reloadFailed(
                    'boot file write failed after live switch; rolled back to keep live and disk in sync: ' . $e->getMessage(),
                );
            }
        }

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        $this->lastLiveRoute = new LiveRoute(
            activeColor: $desired->activeColor,
            upstreamHost: $desired->upstream->host,
            upstreamPort: $desired->upstream->port,
            weight: $desired->weight,
        );

        return new CutoverResult(
            succeeded: true,
            reverted: false,
            drainedConnections: $drainResult->drained,
            forciblyClosed: $drainResult->forciblyClosed,
            durationMs: $durationMs,
            verifiedLiveUpstream: true,
            detail: sprintf('cutover to %s verified', $desired->activeColor->value),
        );
    }

    /**
     * Resolve the cutover config for a route via the precedence collaborator when wired, else the
     * from-scratch generator (preserves behavior for callers/tests that construct the router without
     * an assembler).
     */
    private function assemble(DesiredRoute $desired): AssembledEdgeConfig
    {
        if ($this->configAssembler !== null) {
            return $this->configAssembler->assembleForRoute($desired);
        }

        return new AssembledEdgeConfig(
            config: $this->configGenerator->generateForRoute($desired, $this->adminListen),
            usedBaseConfig: false,
        );
    }

    /** @return array<string, mixed>|null the live config to roll back to, or null if none/unreadable */
    private function snapshotLiveConfig(): ?array
    {
        try {
            $config = $this->adminClient->currentConfig();
        } catch (\Throwable) {
            return null;
        }

        return $config === [] ? null : $config;
    }

    /** @param array<string, mixed>|null $lastKnownGood */
    private function rollback(?array $lastKnownGood): void
    {
        if ($lastKnownGood === null) {
            return;
        }

        try {
            $this->adminClient->load($lastKnownGood);
        } catch (\Throwable) {
            // The rollback load failed too; nothing more we can safely do here. The caller still
            // throws, so the deploy aborts and surfaces the original failure.
        }
    }

    public function liveRoute(): ?LiveRoute
    {
        if ($this->lastLiveRoute !== null) {
            return $this->lastLiveRoute;
        }

        $config = $this->adminClient->currentConfig();
        if ($config === []) {
            return null;
        }

        return $this->extractLiveRouteFromConfig($config);
    }

    public function reconcile(DesiredRoute $desired): ReconcileResult
    {
        $live = $this->liveRoute();

        if ($live !== null && $live->equalsDesired($desired)) {
            return new ReconcileResult(inSync: true, detail: 'live matches desired');
        }

        $result = $this->cutover($desired);

        return new ReconcileResult(
            inSync: false,
            drifted: true,
            corrected: $result->succeeded,
            detail: sprintf('reconciled to %s', $desired->activeColor->value),
        );
    }

    private function verifyLiveUpstream(DesiredRoute $desired): bool
    {
        try {
            $config = $this->adminClient->currentConfig();
        } catch (\Throwable) {
            return false;
        }

        $expectedDial = sprintf('%s:%d', $desired->upstream->host, $desired->upstream->port);

        return $this->configContainsDial($config, $expectedDial);
    }

    /**
     * True when the config carries a tls.automation policy whose subjects include the domain — the
     * guarantee that a /load will keep serving the domain's certificate rather than Caddy's internal
     * default (GAP-D).
     *
     * @param array<string, mixed> $config
     */
    private function configRetainsTls(array $config, string $domain): bool
    {
        $policies = $config['apps']['tls']['automation']['policies'] ?? null;
        if (!is_array($policies)) {
            return false;
        }

        foreach ($policies as $policy) {
            $subjects = $policy['subjects'] ?? [];
            if (is_array($subjects) && in_array($domain, $subjects, true)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $config */
    private function configContainsDial(array $config, string $dial): bool
    {
        $json = json_encode($config, \JSON_THROW_ON_ERROR);

        return str_contains($json, '"dial":"' . $dial . '"')
            || str_contains($json, '"dial": "' . $dial . '"');
    }

    /** @param array<string, mixed> $config */
    private function extractLiveRouteFromConfig(array $config): ?LiveRoute
    {
        $json = json_encode($config, \JSON_THROW_ON_ERROR);

        if (preg_match('/"dial"\s*:\s*"([^"]+):(\d+)"/', $json, $m)) {
            $host = $m[1];
            $port = (int) $m[2];
            $color = $this->guessColorFromHost($host);

            return new LiveRoute(
                activeColor: $color,
                upstreamHost: $host,
                upstreamPort: $port,
            );
        }

        return null;
    }

    private function guessColorFromHost(string $host): ActiveColor
    {
        if (str_contains($host, 'blue')) {
            return ActiveColor::Blue;
        }

        if (str_contains($host, 'green')) {
            return ActiveColor::Green;
        }

        return ActiveColor::None;
    }
}
