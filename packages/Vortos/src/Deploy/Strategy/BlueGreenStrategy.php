<?php

declare(strict_types=1);

namespace Vortos\Deploy\Strategy;

use Vortos\Deploy\Plan\DeployContext;
use Vortos\Deploy\Plan\DeployPhase;
use Vortos\Deploy\Plan\DeployStep;
use Vortos\Deploy\Plan\PhaseKind;
use Vortos\Deploy\Plan\StepAction;
use Vortos\Deploy\Target\ActiveColor;
use Vortos\Deploy\Target\DeployCapability;
use Vortos\OpsKit\Driver\Capability\RequiredCapabilities;

final class BlueGreenStrategy implements DeployStrategyInterface
{
    public function key(): DeployStrategy
    {
        return DeployStrategy::BlueGreen;
    }

    public function requires(): RequiredCapabilities
    {
        return RequiredCapabilities::of([
            DeployCapability::BlueGreen,
            DeployCapability::HealthGate,
        ]);
    }

    public function phases(DeployContext $context): array
    {
        $phases = [];
        $stagedColor = $context->currentState->activeColor->opposite();
        $digest = $context->desiredManifest->imageDigest;
        $repository = $context->desiredManifest->imageRepository;

        if (!$context->desiredManifest->schemaFingerprint->isEmpty()) {
            $phases[] = new DeployPhase(PhaseKind::ExpandMigrate, [
                new DeployStep(
                    StepAction::RunMigrations,
                    'Run expand-phase migrations',
                    ['fingerprint' => $context->desiredManifest->schemaFingerprint->hash],
                ),
            ]);
        }

        // B20: only emitted when the deployment uses an external supervisord; ride-color topologies
        // (the ssh-compose default) get no supervisorctl phase — workers ride the compose color.
        foreach (WorkerRolloutPhaseFactory::phasesFor($context) as $phase) {
            $phases[] = $phase;
        }

        // Converge the edge service (compose + base config) before staging/cutover, idempotently —
        // recreate only on change. This is what makes "edit base config -> deploy -> edge updates" flow
        // end-to-end; without it the edge compose was only ever produced by the one-off edge:init.
        $phases[] = new DeployPhase(PhaseKind::ReconcileEdge, [
            new DeployStep(
                StepAction::ReconcileEdge,
                'Reconcile the edge service (recreate only on change)',
                ['env' => $context->desiredManifest->environment],
            ),
        ]);

        $phases[] = new DeployPhase(PhaseKind::StageColor, [
            new DeployStep(
                StepAction::PullImage,
                sprintf('Pull image @%s to staged color', $digest),
                ['image_digest' => $digest, 'image_repository' => $repository],
            ),
            new DeployStep(
                StepAction::StartContainer,
                sprintf('Start %s container', $stagedColor->value),
                ['color' => $stagedColor->value, 'image_digest' => $digest, 'image_repository' => $repository],
            ),
        ]);

        $phases[] = new DeployPhase(PhaseKind::HealthGate, [
            new DeployStep(
                StepAction::CheckHealth,
                sprintf('Wait for %s /health/ready', $stagedColor->value),
                [
                    'color' => $stagedColor->value,
                    'timeout_seconds' => $context->definition->healthGateTimeoutSeconds,
                    'stabilization_seconds' => $context->definition->healthGateStabilizationSeconds,
                ],
            ),
        ]);

        $phases[] = new DeployPhase(PhaseKind::Smoke, [
            new DeployStep(
                StepAction::RunSmoke,
                'Run smoke tests against staged color',
                ['color' => $stagedColor->value],
            ),
        ]);

        $phases[] = new DeployPhase(PhaseKind::Cutover, [
            new DeployStep(
                StepAction::SwitchUpstream,
                sprintf('Switch upstream to %s', $stagedColor->value),
                [
                    'from' => $context->currentState->activeColor->value,
                    'to' => $stagedColor->value,
                    'drain_deadline_seconds' => 30,
                    'image_digest' => $digest, 'image_repository' => $repository,
                ],
            ),
        ]);

        $phases[] = new DeployPhase(PhaseKind::Promote, [
            new DeployStep(
                StepAction::UpdateState,
                sprintf('Promote %s as active', $stagedColor->value),
                ['color' => $stagedColor->value, 'image_digest' => $digest, 'image_repository' => $repository],
            ),
        ]);

        // Decommission the previous color — the final phase, and the ONLY point at which the old color is
        // torn down. Historically blue-green emitted no teardown at all, so the previous color (and, on a
        // ride-color topology, its entire worker fleet) kept running indefinitely after every deploy —
        // idle-polling Kafka and doubling broker load until a later deploy happened to reuse the slot.
        //
        // Teardown is strictly gated: it runs only after StageColor + HealthGate (with stabilization) +
        // Smoke + Cutover + Promote have all succeeded, AND after a final post-cutover CheckHealth confirms
        // the newly-promoted color is genuinely serving live traffic. If that re-check fails the step throws
        // and the deploy aborts BEFORE any StopContainer — so the old color is left running and autoRollback
        // can route back to it. The old color's image is retained (see pruneImages keep) for fast rollback.
        $decommissionSteps = [
            new DeployStep(
                StepAction::CheckHealth,
                sprintf('Confirm promoted %s is serving before reaping old color', $stagedColor->value),
                [
                    'color' => $stagedColor->value,
                    'timeout_seconds' => $context->definition->healthGateTimeoutSeconds,
                    'stabilization_seconds' => 0,
                ],
            ),
        ];

        // Only reap when there is a real previous color. On a bootstrap deploy the active color is None
        // (opposite() staged Blue), so there is nothing to tear down — skip the StopContainer entirely
        // rather than emit a no-op teardown of a vortos-app-none project that never existed.
        $previousColor = $context->currentState->activeColor;
        if ($previousColor !== ActiveColor::None && $previousColor !== $stagedColor) {
            $decommissionSteps[] = new DeployStep(
                StepAction::StopContainer,
                sprintf('Tear down previous color %s (app + worker)', $previousColor->value),
                ['color' => $previousColor->value],
            );
        }

        $phases[] = new DeployPhase(PhaseKind::Decommission, $decommissionSteps);

        return $phases;
    }
}
