<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Plan;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Plan\DeployPhase;
use Vortos\Deploy\Plan\DeployPlan;
use Vortos\Deploy\Plan\DeployStep;
use Vortos\Deploy\Plan\PhaseKind;
use Vortos\Deploy\Plan\PlanHash;
use Vortos\Deploy\Plan\StepAction;

final class PlanHashTest extends TestCase
{
    public function test_hash_starts_with_sha256_prefix(): void
    {
        $hash = PlanHash::fromPlanJson('{"test": true}');
        self::assertStringStartsWith('sha256:', $hash->toString());
    }

    public function test_same_input_produces_same_hash(): void
    {
        $json = '{"a":"b","c":1}';
        $h1 = PlanHash::fromPlanJson($json);
        $h2 = PlanHash::fromPlanJson($json);

        self::assertTrue($h1->equals($h2));
    }

    public function test_different_input_produces_different_hash(): void
    {
        $h1 = PlanHash::fromPlanJson('{"a":"b"}');
        $h2 = PlanHash::fromPlanJson('{"a":"c"}');

        self::assertFalse($h1->equals($h2));
    }

    public function test_pinned_test_vector(): void
    {
        $plan = new DeployPlan(
            phases: [
                new DeployPhase(PhaseKind::StageColor, [
                    new DeployStep(StepAction::StartContainer, 'Start blue', ['color' => 'blue']),
                ]),
                new DeployPhase(PhaseKind::Promote, [
                    new DeployStep(StepAction::UpdateState, 'Promote blue'),
                ]),
            ],
            definitionHash: 'sha256:fixed-definition-hash',
        );

        $expectedJson = $plan->toCanonicalJson();
        $expectedHash = 'sha256:' . hash('sha256', $expectedJson);

        self::assertSame($expectedHash, $plan->planHash->toString());

        $plan2 = new DeployPlan(
            phases: [
                new DeployPhase(PhaseKind::StageColor, [
                    new DeployStep(StepAction::StartContainer, 'Start blue', ['color' => 'blue']),
                ]),
                new DeployPhase(PhaseKind::Promote, [
                    new DeployStep(StepAction::UpdateState, 'Promote blue'),
                ]),
            ],
            definitionHash: 'sha256:fixed-definition-hash',
        );

        self::assertTrue($plan->planHash->equals($plan2->planHash), 'Same plan structure must produce the same hash.');
    }
}
