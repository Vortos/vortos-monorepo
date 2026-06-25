<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Plan;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Plan\DeployPhase;
use Vortos\Deploy\Plan\DeployPlan;
use Vortos\Deploy\Plan\DeployStep;
use Vortos\Deploy\Plan\PhaseKind;
use Vortos\Deploy\Plan\PlanRenderer;
use Vortos\Deploy\Plan\StepAction;
use Vortos\Secrets\Preflight\SecretReference;
use Vortos\Secrets\Value\SecretKey;

final class PlanRendererTest extends TestCase
{
    public function test_text_output_contains_phase_names(): void
    {
        $plan = $this->createSamplePlan();
        $renderer = new PlanRenderer();

        $text = $renderer->toText($plan);

        self::assertStringContainsString('stage-color', $text);
        self::assertStringContainsString('promote', $text);
        self::assertStringContainsString('Deploy Plan', $text);
        self::assertStringContainsString('Hash: sha256:', $text);
    }

    public function test_json_output_is_valid_json(): void
    {
        $plan = $this->createSamplePlan();
        $renderer = new PlanRenderer();

        $json = $renderer->toJson($plan);
        $decoded = json_decode($json, true);

        self::assertIsArray($decoded);
        self::assertArrayHasKey('plan_hash', $decoded);
        self::assertArrayHasKey('phases', $decoded);
    }

    public function test_text_output_shows_secret_references_not_values(): void
    {
        $plan = new DeployPlan(
            phases: [
                new DeployPhase(PhaseKind::StageColor, [
                    new DeployStep(
                        StepAction::StartContainer,
                        'Start container',
                        [],
                        [new SecretReference(SecretKey::fromString('db_password'))],
                    ),
                ]),
            ],
            definitionHash: 'sha256:test',
        );

        $renderer = new PlanRenderer();
        $text = $renderer->toText($plan);

        self::assertStringContainsString('db_password', $text);
        self::assertStringNotContainsString('actual-password', $text);
    }

    public function test_json_output_shows_secret_references_not_values(): void
    {
        $plan = new DeployPlan(
            phases: [
                new DeployPhase(PhaseKind::StageColor, [
                    new DeployStep(
                        StepAction::StartContainer,
                        'Start container',
                        [],
                        [new SecretReference(SecretKey::fromString('api_key'))],
                    ),
                ]),
            ],
            definitionHash: 'sha256:test',
        );

        $renderer = new PlanRenderer();
        $json = $renderer->toJson($plan);

        self::assertStringContainsString('api_key', $json);
        self::assertStringContainsString('secret_references', $json);
    }

    public function test_text_output_shows_params(): void
    {
        $plan = new DeployPlan(
            phases: [
                new DeployPhase(PhaseKind::Cutover, [
                    new DeployStep(StepAction::SwitchUpstream, 'Switch', ['from' => 'blue', 'to' => 'green']),
                ]),
            ],
            definitionHash: 'sha256:test',
        );

        $text = (new PlanRenderer())->toText($plan);

        self::assertStringContainsString('from=blue', $text);
        self::assertStringContainsString('to=green', $text);
    }

    private function createSamplePlan(): DeployPlan
    {
        return new DeployPlan(
            phases: [
                new DeployPhase(PhaseKind::StageColor, [
                    new DeployStep(StepAction::StartContainer, 'Start green'),
                ]),
                new DeployPhase(PhaseKind::Promote, [
                    new DeployStep(StepAction::UpdateState, 'Promote green'),
                ]),
            ],
            definitionHash: 'sha256:sample',
        );
    }
}
