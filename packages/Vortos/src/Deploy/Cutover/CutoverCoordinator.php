<?php

declare(strict_types=1);

namespace Vortos\Deploy\Cutover;

use Vortos\Deploy\Compose\ColorEndpoint;
use Vortos\Deploy\Exception\CutoverFailedException;
use Vortos\Deploy\Exception\CutoverRevertedException;
use Vortos\Deploy\State\CurrentRelease;
use Vortos\Deploy\State\CurrentReleaseStoreInterface;

final class CutoverCoordinator
{
    public function __construct(
        private readonly EdgeRouterInterface $edgeRouter,
        private readonly CurrentReleaseStoreInterface $releaseStore,
        private readonly CutoverEventRecorderInterface $eventRecorder,
    ) {}

    public function cutover(
        DesiredRoute $desired,
        string $imageDigest,
        string $buildId,
        string $planHash,
        ColorEndpoint $previousEndpoint,
    ): CutoverResult {
        $prev = $this->releaseStore->currentRelease($desired->env);
        $prevRoute = $prev !== null ? $this->routeFor($prev, $previousEndpoint) : null;

        try {
            $result = $this->edgeRouter->cutover($desired);

            if (!$result->verifiedLiveUpstream) {
                throw CutoverFailedException::verifyMismatch(
                    $desired->upstream->host . ':' . $desired->upstream->port,
                    'unverified',
                );
            }
        } catch (CutoverFailedException $e) {
            if ($prevRoute !== null) {
                $this->edgeRouter->cutover($prevRoute);
                $this->eventRecorder->recordRevert($desired, $prevRoute);

                throw CutoverRevertedException::afterVerifyFailure(
                    $prevRoute->activeColor->value,
                    $e->getMessage(),
                );
            }

            $this->eventRecorder->recordRevert($desired, null);

            throw CutoverRevertedException::noPreviousColor($e->getMessage());
        }

        $newGeneration = ($prev !== null ? $prev->generation : 0) + 1;
        $release = new CurrentRelease(
            env: $desired->env,
            activeColor: $desired->activeColor,
            imageDigest: $imageDigest,
            buildId: $buildId,
            planHash: $planHash,
            recordedAt: new \DateTimeImmutable(),
            generation: $newGeneration,
        );

        $this->releaseStore->recordCurrentRelease($release);
        $this->eventRecorder->recordCutover($desired, $result);

        return $result;
    }

    private function routeFor(CurrentRelease $release, ColorEndpoint $endpoint): DesiredRoute
    {
        return new DesiredRoute(
            env: $release->env,
            activeColor: $release->activeColor,
            upstream: $endpoint,
        );
    }
}
