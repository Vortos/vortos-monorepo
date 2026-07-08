<?php

declare(strict_types=1);

namespace Vortos\Deploy\Driver\Caddy;

use Vortos\Deploy\Cutover\CutoverResult;
use Vortos\Deploy\Cutover\DesiredRoute;
use Vortos\Deploy\Cutover\EdgeConfigGenerator;
use Vortos\Deploy\Cutover\EdgeRouterInterface;
use Vortos\Deploy\Cutover\LiveRoute;
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
    ) {}

    public function capabilities(): CapabilityDescriptor
    {
        return CaddyCapability::descriptor();
    }

    public function cutover(DesiredRoute $desired): CutoverResult
    {
        $startTime = microtime(true);

        // Single source of truth for the edge config shape: always includes the host matcher +
        // tls.automation for the route's domain (when set), so a /load PRESERVES the domain's cert
        // instead of clobbering it (GAP-D). The complement dial for a canary ramp is derived from the
        // route's container port, never a hardcoded value.
        $config = $this->configGenerator->generateForRoute($desired, $this->adminListen);

        // Guard: a domain'd route must carry its tls.automation subject, or a cutover would silently
        // drop the certificate. Fail closed before touching the live edge.
        if ($desired->domain !== null && !$this->configRetainsTls($config, $desired->domain)) {
            throw CutoverFailedException::verifyMismatch(
                $desired->domain,
                'generated cutover config is missing the domain tls.automation policy',
            );
        }

        $this->adminClient->load($config);

        // Persist the routing intent so a new / horizontally-scaled edge node reconstructs the
        // active-color route from the shared control-plane store (Redis by default). The live switch
        // above already happened via the Admin API; this is durability, not the switch.
        $this->stateStore->save(EdgeState::fromRoute($desired));

        // Persist the EXACT rendered config to the edge's on-disk boot file — the same file Caddy
        // boots from ("caddy run --config"). Without this the boot file only reflects the last
        // "docker compose up" (edge-init runs solely on the compose depends_on gate, never on a bare
        // Docker daemon restart), so a daemon restart would resurrect a stale route or, on a
        // push-only edge, come up empty — the exact outage this closes. With it, a cold restart
        // reloads the current route verbatim, no admin re-push and no redeploy required.
        $this->bootConfigWriter?->write(json_encode(
            $config,
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT,
        ));

        $drainResult = $this->drainObserver->awaitDrain($desired->drainDeadlineSeconds);

        if (!$drainResult->metricsReachable) {
            throw CutoverFailedException::metricsUnreachable(
                'drain verification failed — metrics became unreachable during drain window',
            );
        }

        $verified = $this->verifyLiveUpstream($desired);
        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        if (!$verified) {
            throw CutoverFailedException::verifyMismatch(
                $desired->upstream->host . ':' . $desired->upstream->port,
                'current config does not match',
            );
        }

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
