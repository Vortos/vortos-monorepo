<?php

declare(strict_types=1);

namespace Vortos\Deploy\Strategy;

use Vortos\Deploy\Definition\WorkerTopology;
use Vortos\Deploy\Plan\DeployContext;
use Vortos\Deploy\Plan\DeployPhase;
use Vortos\Deploy\Plan\DeployStep;
use Vortos\Deploy\Plan\PhaseKind;
use Vortos\Deploy\Plan\StepAction;

/**
 * Single source of truth for the supervisorctl-driven RollWorkers phase (B20).
 *
 * Every strategy used to emit this phase unconditionally, which drives
 * {@see \Vortos\Deploy\Driver\Supervisor\SupervisorWorkerController} → supervisorctl stop/start.
 * That assumes a persistent supervisord reachable from where the deploy runs. In the blessed
 * edge-router / compose-color topology ({@see WorkerTopology::RideColor}) the deploy runs in a
 * throwaway one-shot with no supervisord and the workers ride the compose color, so the phase both
 * fails (exit 7) and is redundant. Emitting it is now gated on the declared {@see WorkerTopology};
 * keeping that gate in one place stops the four strategies from drifting apart.
 */
final class WorkerRolloutPhaseFactory
{
    /**
     * Returns the RollWorkers phase as a single-element list when the deployment uses an external
     * supervisord, or an empty list when workers ride the compose color. Splat the result into the
     * strategy's phase array at the position the rollout belongs.
     *
     * @return list<DeployPhase>
     */
    public static function phasesFor(DeployContext $context): array
    {
        if (!$context->definition->workerTopology->usesExternalSupervisor()) {
            return [];
        }

        $digest = $context->desiredManifest->imageDigest;
        $repository = $context->desiredManifest->imageRepository;

        return [
            new DeployPhase(PhaseKind::RollWorkers, [
                new DeployStep(
                    StepAction::DrainWorker,
                    'Rolling drain and restart workers',
                    [
                        'deadline_seconds' => $context->definition->workerDrainDeadlineSeconds,
                        'image_digest' => $digest,
                        'image_repository' => $repository,
                    ],
                ),
                new DeployStep(
                    StepAction::StartWorker,
                    'Noop — workers launched during drain rollout',
                    ['image_digest' => $digest, 'image_repository' => $repository],
                ),
            ]),
        ];
    }
}
