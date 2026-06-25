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

final class RecreateStrategy implements DeployStrategyInterface
{
    public function key(): DeployStrategy
    {
        return DeployStrategy::Recreate;
    }

    public function requires(): RequiredCapabilities
    {
        return RequiredCapabilities::of([
            DeployCapability::AcceptsDowntime,
        ]);
    }

    public function phases(DeployContext $context): array
    {
        $digest = $context->desiredManifest->imageDigest;
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

        $phases[] = new DeployPhase(PhaseKind::RollWorkers, [
            new DeployStep(
                StepAction::DrainWorker,
                'Rolling drain and restart workers',
                ['deadline_seconds' => $context->definition->workerDrainDeadlineSeconds, 'image_digest' => $digest],
            ),
        ]);

        $phases[] = new DeployPhase(PhaseKind::StageColor, [
            new DeployStep(
                StepAction::StopContainer,
                'Stop all existing containers (downtime begins)',
            ),
            new DeployStep(
                StepAction::PullImage,
                sprintf('Pull new image @%s', $digest),
                ['image_digest' => $digest],
            ),
            new DeployStep(
                StepAction::StartContainer,
                'Start new containers (downtime ends)',
                ['image_digest' => $digest],
            ),
        ]);

        $phases[] = new DeployPhase(PhaseKind::Promote, [
            new DeployStep(
                StepAction::UpdateState,
                'Record new state',
                ['image_digest' => $digest],
            ),
        ]);

        return $phases;
    }
}
