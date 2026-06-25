<?php

declare(strict_types=1);

namespace Vortos\Deploy\Cutover;

use Vortos\Deploy\Compose\ComposeProjectFactory;
use Vortos\Deploy\State\CurrentReleaseStoreInterface;

final class EdgeReconciler
{
    public function __construct(
        private readonly EdgeRouterInterface $edgeRouter,
        private readonly CurrentReleaseStoreInterface $releaseStore,
        private readonly ComposeProjectFactory $composeFactory,
        private readonly ReconcileRateLimiter $rateLimiter,
        private readonly CutoverEventRecorderInterface $eventRecorder,
    ) {}

    public function reconcile(string $env): ReconcileResult
    {
        $desired = $this->releaseStore->currentRelease($env);

        if ($desired === null) {
            return new ReconcileResult(inSync: true, detail: 'no release recorded');
        }

        $endpoint = $this->composeFactory->endpointFor($desired->activeColor);
        $desiredRoute = new DesiredRoute(
            env: $env,
            activeColor: $desired->activeColor,
            upstream: $endpoint,
        );

        $live = $this->edgeRouter->liveRoute();

        if ($live !== null && $live->equalsDesired($desiredRoute)) {
            return new ReconcileResult(inSync: true, detail: 'live matches desired');
        }

        if (!$this->rateLimiter->allow($env)) {
            $this->eventRecorder->recordDrift($desiredRoute, $live);

            return new ReconcileResult(
                inSync: false,
                drifted: true,
                corrected: false,
                skippedRateLimited: true,
                detail: 'drift detected but rate-limited',
            );
        }

        $this->edgeRouter->cutover($desiredRoute);
        $this->rateLimiter->record($env);
        $this->eventRecorder->recordDrift($desiredRoute, $live);

        return new ReconcileResult(
            inSync: false,
            drifted: true,
            corrected: true,
            detail: sprintf('corrected drift: applied %s', $desired->activeColor->value),
        );
    }
}
