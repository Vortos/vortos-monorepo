<?php

declare(strict_types=1);

namespace Vortos\Deploy\Strategy;

use Vortos\Deploy\Plan\DeployContext;
use Vortos\Deploy\Plan\DeployPhase;
use Vortos\Deploy\Plan\DeployStep;
use Vortos\Deploy\Plan\PhaseKind;
use Vortos\Deploy\Plan\StepAction;
use Vortos\Deploy\Target\DeployCapability;
use Vortos\OpsKit\Driver\Capability\RequiredCapabilities;

final class RollingStrategy implements DeployStrategyInterface
{
    public function key(): DeployStrategy
    {
        return DeployStrategy::Rolling;
    }

    public function requires(): RequiredCapabilities
    {
        return RequiredCapabilities::of([
            DeployCapability::RollingAcrossNodes,
            DeployCapability::HealthGate,
        ]);
    }

    public function phases(DeployContext $context): array
    {
        $digest = $context->desiredManifest->imageDigest;
        $repository = $context->desiredManifest->imageRepository;
        $phases = [];

        if (!$context->desiredManifest->schemaFingerprint->isEmpty()) {
            $phases[] = new DeployPhase(PhaseKind::ExpandMigrate, [
                new DeployStep(
                    StepAction::RunMigrations,
                    'Run expand-phase migrations',
                    ['fingerprint' => $context->desiredManifest->schemaFingerprint->hash],
                ),
            ]);
        }

        // B20: gated on WorkerTopology — ride-color topologies (the ssh-compose default) emit none.
        foreach (WorkerRolloutPhaseFactory::phasesFor($context) as $phase) {
            $phases[] = $phase;
        }

        $phases[] = new DeployPhase(PhaseKind::StageColor, [
            new DeployStep(
                StepAction::StartContainer,
                'Rolling update containers one by one',
                ['image_digest' => $digest, 'image_repository' => $repository, 'strategy' => 'rolling'],
            ),
        ]);

        $phases[] = new DeployPhase(PhaseKind::HealthGate, [
            new DeployStep(
                StepAction::CheckHealth,
                'Verify health after each node update',
                [
                    'timeout_seconds' => $context->definition->healthGateTimeoutSeconds,
                    'stabilization_seconds' => $context->definition->healthGateStabilizationSeconds,
                ],
            ),
        ]);

        $phases[] = new DeployPhase(PhaseKind::Promote, [
            new DeployStep(
                StepAction::UpdateState,
                'All nodes updated — record new state',
                ['image_digest' => $digest, 'image_repository' => $repository],
            ),
        ]);

        return $phases;
    }
}
