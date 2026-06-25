<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Plan;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Plan\CurrentDeployState;
use Vortos\Deploy\Plan\DeployPhase;
use Vortos\Deploy\Plan\DeployPlan;
use Vortos\Deploy\Plan\DeployStep;
use Vortos\Deploy\Plan\PhaseKind;
use Vortos\Deploy\Plan\StepAction;
use Vortos\Deploy\Target\ActiveColor;
use Vortos\Release\Schema\SchemaFingerprint;

final class DeployPlanTest extends TestCase
{
    public function test_empty_plan(): void
    {
        $plan = new DeployPlan([], 'sha256:test');

        self::assertTrue($plan->isEmpty());
        self::assertSame(0, $plan->phaseCount());
        self::assertFalse($plan->hasPhase(PhaseKind::Cutover));
    }

    public function test_has_phase(): void
    {
        $plan = new DeployPlan(
            [new DeployPhase(PhaseKind::Cutover, [])],
            'sha256:test',
        );

        self::assertTrue($plan->hasPhase(PhaseKind::Cutover));
        self::assertFalse($plan->hasPhase(PhaseKind::Rollback));
    }

    public function test_to_array_contains_all_fields(): void
    {
        $plan = new DeployPlan(
            [new DeployPhase(PhaseKind::Promote, [new DeployStep(StepAction::UpdateState, 'Done')])],
            'sha256:test',
            'sig123',
            'ci-bot',
        );

        $arr = $plan->toArray();

        self::assertArrayHasKey('plan_hash', $arr);
        self::assertArrayHasKey('definition_hash', $arr);
        self::assertArrayHasKey('phases', $arr);
        self::assertSame('sig123', $arr['signature']);
        self::assertSame('ci-bot', $arr['signed_by']);
    }

    public function test_canonical_json_is_deterministic(): void
    {
        $phases = [
            new DeployPhase(PhaseKind::StageColor, [
                new DeployStep(StepAction::StartContainer, 'Start'),
            ]),
        ];

        $p1 = new DeployPlan($phases, 'sha256:abc');
        $p2 = new DeployPlan($phases, 'sha256:abc');

        self::assertSame($p1->toCanonicalJson(), $p2->toCanonicalJson());
    }

    public function test_signing_ready_fields(): void
    {
        $plan = new DeployPlan([], 'sha256:test', 'signature-data', 'signer-id');

        self::assertSame('signature-data', $plan->signature);
        self::assertSame('signer-id', $plan->signedBy);
    }

    public function test_current_deploy_state_first_deploy(): void
    {
        $state = CurrentDeployState::firstDeploy();

        self::assertSame(ActiveColor::None, $state->activeColor);
        self::assertSame('', $state->currentDigest);
        self::assertTrue($state->appliedFingerprint->isEmpty());
        self::assertFalse($state->pendingContract());
        self::assertSame([], $state->pendingContractMigrations);
        self::assertTrue($state->isFirstDeploy());
    }

    public function test_current_deploy_state_not_first(): void
    {
        $state = new CurrentDeployState(
            ActiveColor::Blue,
            'sha256:' . str_repeat('a', 64),
            new SchemaFingerprint(['m001']),
        );

        self::assertFalse($state->isFirstDeploy());
    }
}
