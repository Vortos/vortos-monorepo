<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Builder\PipelineBuilder;
use Vortos\Pipeline\Builder\StageGate;
use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Model\CommandStep;
use Vortos\Pipeline\Model\Stage;
use Vortos\Pipeline\Model\StageKind;
use Vortos\Pipeline\Model\TriggerEvent;

/**
 * Behavioural coverage for the config surface added to fix B3 (deployment branch), B8 (scalar
 * matrix axis) and B6/G6 (per-job bootstrap steps + quality-stage optionality).
 */
final class PipelineConfigSurfaceBehaviorTest extends TestCase
{
    private function builder(): PipelineBuilder
    {
        return new PipelineBuilder(new StageGate());
    }

    private function stage(PipelineDefinition $definition, StageKind $kind): ?Stage
    {
        foreach ($this->builder()->build($definition)->stages as $stage) {
            if ($stage->kind === $kind) {
                return $stage;
            }
        }

        return null;
    }

    // ── B3: deployment branch ──────────────────────────────────────────────────────────────

    public function test_deployment_branch_drives_triggers_and_deploy_condition(): void
    {
        $definition = new PipelineDefinition(deploymentBranch: 'master');
        $pipeline = $this->builder()->build($definition);

        $pushTrigger = null;
        foreach ($pipeline->triggers as $trigger) {
            if ($trigger->event === TriggerEvent::Push) {
                $pushTrigger = $trigger;
            }
        }
        self::assertNotNull($pushTrigger);
        self::assertSame(['master'], $pushTrigger->branches);

        $deploy = $this->stage($definition, StageKind::Deploy);
        self::assertNotNull($deploy);
        self::assertStringContainsString("refs/heads/master", (string) $deploy->condition);
        self::assertStringNotContainsString('refs/heads/main', (string) $deploy->condition);
    }

    public function test_deployment_branch_defaults_to_main(): void
    {
        $deploy = $this->stage(new PipelineDefinition(), StageKind::Deploy);
        self::assertNotNull($deploy);
        self::assertStringContainsString("refs/heads/main", (string) $deploy->condition);
    }

    public function test_branch_with_whitespace_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PipelineDefinition(deploymentBranch: 'feature branch');
    }

    // ── B8: scalar matrix axis ─────────────────────────────────────────────────────────────

    public function test_environment_matrix_is_a_scalar_list(): void
    {
        $definition = new PipelineDefinition(environments: ['production', 'staging']);
        $deploy = $this->stage($definition, StageKind::Deploy);

        self::assertNotNull($deploy);
        self::assertNotNull($deploy->matrix);
        self::assertSame('environment', $deploy->matrix->axisName);
        // Scalars — not [{environment: production}] — so `${{ matrix.environment }}` resolves.
        self::assertSame(['production', 'staging'], $deploy->matrix->values);
        self::assertSame('${{ matrix.environment }}', $deploy->environment);
    }

    // ── B6/G6: bootstrap steps + optional quality stages ───────────────────────────────────

    public function test_bootstrap_steps_are_injected_into_every_container_booting_job(): void
    {
        $definition = new PipelineDefinition(
            bootstrapSteps: [['name' => 'Prepare env', 'run' => 'cp .env.example .env']],
        );
        $pipeline = $this->builder()->build($definition);

        foreach (['tests', 'analyse', 'agnosticism'] as $jobId) {
            $stage = null;
            foreach ($pipeline->stages as $s) {
                if ($s->id === $jobId) {
                    $stage = $s;
                }
            }
            self::assertNotNull($stage, "Stage {$jobId} must exist.");

            $hasBootstrap = false;
            foreach ($stage->steps as $step) {
                if ($step instanceof CommandStep && $step->run === 'cp .env.example .env') {
                    $hasBootstrap = true;
                }
            }
            self::assertTrue($hasBootstrap, "Bootstrap step must be injected into {$jobId}.");
        }
    }

    public function test_quality_stages_can_be_disabled_and_leave_no_dangling_needs(): void
    {
        $definition = new PipelineDefinition(
            imageRepository: null,
            emitStaticAnalysis: false,
            emitAgnosticism: false,
        );
        $pipeline = $this->builder()->build($definition);

        $ids = array_map(static fn (Stage $s): string => $s->id, $pipeline->stages);
        self::assertNotContains('analyse', $ids);
        self::assertNotContains('agnosticism', $ids);

        $deploy = $this->stage($definition, StageKind::Deploy);
        self::assertNotNull($deploy);
        self::assertSame(['tests'], $deploy->needs, 'Deploy must only wait on jobs that are emitted.');
    }
}
