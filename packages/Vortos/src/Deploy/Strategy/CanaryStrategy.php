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

final class CanaryStrategy implements DeployStrategyInterface
{
    /** @var list<int> */
    private const DEFAULT_WEIGHT_STEPS = [5, 25, 50, 100];

    public function key(): DeployStrategy
    {
        return DeployStrategy::Canary;
    }

    public function requires(): RequiredCapabilities
    {
        return RequiredCapabilities::of([
            DeployCapability::Canary,
            DeployCapability::BlueGreen,
            DeployCapability::HealthGate,
        ]);
    }

    public function phases(DeployContext $context): array
    {
        $stagedColor = $context->currentState->activeColor->opposite();
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
                StepAction::PullImage,
                sprintf('Pull image @%s to staged color', $digest),
                ['image_digest' => $digest, 'image_repository' => $repository],
            ),
            new DeployStep(
                StepAction::StartContainer,
                sprintf('Start %s container for canary', $stagedColor->value),
                ['color' => $stagedColor->value, 'image_digest' => $digest, 'image_repository' => $repository],
            ),
        ]);

        $phases[] = new DeployPhase(PhaseKind::HealthGate, [
            new DeployStep(
                StepAction::CheckHealth,
                sprintf('Wait for %s /health/ready', $stagedColor->value),
                ['color' => $stagedColor->value, 'timeout_seconds' => 60],
            ),
        ]);

        foreach (self::DEFAULT_WEIGHT_STEPS as $weight) {
            $phases[] = new DeployPhase(PhaseKind::Cutover, [
                new DeployStep(
                    StepAction::WeightedRoute,
                    sprintf('Route %d%% traffic to %s', $weight, $stagedColor->value),
                    ['weight' => $weight, 'color' => $stagedColor->value],
                ),
                new DeployStep(
                    StepAction::CheckHealth,
                    sprintf('Verify SLOs at %d%% weight', $weight),
                    ['weight' => $weight],
                ),
            ]);
        }

        $phases[] = new DeployPhase(PhaseKind::Promote, [
            new DeployStep(
                StepAction::UpdateState,
                sprintf('Promote %s as active (canary complete)', $stagedColor->value),
                ['color' => $stagedColor->value, 'image_digest' => $digest, 'image_repository' => $repository],
            ),
        ]);

        return $phases;
    }
}
