<?php

declare(strict_types=1);

namespace Vortos\Deploy\Driver\Caddy;

use Vortos\Deploy\Cutover\CutoverResult;
use Vortos\Deploy\Cutover\DesiredRoute;
use Vortos\Deploy\Cutover\EdgeRouterInterface;
use Vortos\Deploy\Cutover\LiveRoute;
use Vortos\Deploy\Cutover\ReconcileResult;
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
        private readonly CaddyConfigFragment $configFragment,
        private readonly MountedConfigWriter $configWriter,
        private readonly DrainObserver $drainObserver,
    ) {}

    public function capabilities(): CapabilityDescriptor
    {
        return CaddyCapability::descriptor();
    }

    public function cutover(DesiredRoute $desired): CutoverResult
    {
        $startTime = microtime(true);

        if ($desired->weight < 100 && $desired->weight > 0) {
            // Weighted canary ramp — use the complement upstream for the stable color
            $otherColor = $desired->activeColor->opposite();
            $otherDial = sprintf('app-%s:8081', $otherColor->value);
            $config = $this->configFragment->buildWeighted($desired, $otherDial);
        } else {
            $config = $this->configFragment->build($desired);
        }

        $json = json_encode($config, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);

        $this->configWriter->write($json);
        $this->adminClient->load($config);

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
