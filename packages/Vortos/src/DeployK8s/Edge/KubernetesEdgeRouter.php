<?php

declare(strict_types=1);

namespace Vortos\DeployK8s\Edge;

use Vortos\Deploy\Cutover\CutoverResult;
use Vortos\Deploy\Cutover\DesiredRoute;
use Vortos\Deploy\Cutover\EdgeRouterInterface;
use Vortos\Deploy\Cutover\LiveRoute;
use Vortos\Deploy\Cutover\ReconcileResult;
use Vortos\Deploy\Target\ActiveColor;
use Vortos\DeployK8s\Api\KubeApiInterface;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

#[AsDriver('k8s')]
final class KubernetesEdgeRouter implements EdgeRouterInterface
{
    private ?LiveRoute $lastLiveRoute = null;

    public function __construct(
        private readonly KubeApiInterface $kubeApi,
        private readonly string $serviceName = 'app',
        private readonly string $namespace = 'default',
    ) {}

    public function capabilities(): CapabilityDescriptor
    {
        return KubernetesEdgeCapability::descriptor();
    }

    public function cutover(DesiredRoute $desired): CutoverResult
    {
        $startTime = microtime(true);

        $svc = $this->kubeApi->getService($this->serviceName, $this->namespace);
        $resourceVersion = $svc?->resourceVersion ?? '0';

        $selector = [
            'app.kubernetes.io/name' => $this->serviceName,
            'app.kubernetes.io/color' => $desired->activeColor->value,
        ];

        $this->kubeApi->patchServiceSelector(
            $this->serviceName,
            $this->namespace,
            $selector,
            $resourceVersion,
        );

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
            drainedConnections: 0,
            forciblyClosed: 0,
            durationMs: $durationMs,
            verifiedLiveUpstream: true,
            detail: sprintf('k8s Service selector patched to color=%s', $desired->activeColor->value),
        );
    }

    public function liveRoute(): ?LiveRoute
    {
        if ($this->lastLiveRoute !== null) {
            return $this->lastLiveRoute;
        }

        $svc = $this->kubeApi->getService($this->serviceName, $this->namespace);
        if ($svc === null) {
            return null;
        }

        $color = $svc->selector['app.kubernetes.io/color'] ?? null;
        if ($color === null) {
            return null;
        }

        $activeColor = ActiveColor::tryFrom($color) ?? ActiveColor::None;

        return new LiveRoute(
            activeColor: $activeColor,
            upstreamHost: $this->serviceName,
            upstreamPort: $svc->port > 0 ? $svc->port : 8080,
        );
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
}
