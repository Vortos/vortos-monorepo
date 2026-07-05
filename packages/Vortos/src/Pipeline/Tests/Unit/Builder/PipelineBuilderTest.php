<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Builder\PipelineBuilder;
use Vortos\Pipeline\Builder\StageGate;
use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Definition\QualityMode;
use Vortos\Pipeline\Model\ActionStep;
use Vortos\Pipeline\Model\CommandStep;
use Vortos\Pipeline\Model\SplitPackage;
use Vortos\Pipeline\Model\StageKind;
use Vortos\Pipeline\Model\TriggerEvent;

final class PipelineBuilderTest extends TestCase
{
    public function test_build_with_default_definition_produces_correct_stages(): void
    {
        $builder = new PipelineBuilder(new StageGate());
        $pipeline = $builder->build(new PipelineDefinition());

        $ids = array_map(fn ($s) => $s->id, $pipeline->stages);

        $this->assertContains('tests', $ids);
        $this->assertContains('analyse', $ids);
        $this->assertContains('agnosticism', $ids);
        $this->assertContains('deploy', $ids);
    }

    private function stageById(PipelineDefinition $definition, string $id): ?object
    {
        $pipeline = (new PipelineBuilder(new StageGate()))->build($definition);
        foreach ($pipeline->stages as $stage) {
            if ($stage->id === $id) {
                return $stage;
            }
        }

        return null;
    }

    private function commandStepRun(object $stage, string $stepName): ?string
    {
        foreach ($stage->steps as $step) {
            if ($step instanceof CommandStep && $step->name === $stepName) {
                return $step->run;
            }
        }

        return null;
    }

    public function test_enforce_mode_emits_the_bare_quality_command(): void
    {
        // G6: default (enforce) keeps the fail-closed command as-is.
        $stage = $this->stageById(new PipelineDefinition(), 'analyse');
        self::assertNotNull($stage);

        $run = $this->commandStepRun($stage, 'Run PHPStan');
        self::assertSame('./vendor/bin/phpstan analyse', $run);
    }

    public function test_warn_mode_guards_on_tool_presence_and_never_fails_the_build(): void
    {
        // G6: warn mode skips cleanly if the tool is absent and surfaces issues as warnings.
        $definition = new PipelineDefinition(staticAnalysisMode: QualityMode::Warn);
        $stage = $this->stageById($definition, 'analyse');
        self::assertNotNull($stage);

        $run = (string) $this->commandStepRun($stage, 'Run PHPStan');
        self::assertStringContainsString('command -v ./vendor/bin/phpstan', $run);
        self::assertStringContainsString('exit 0', $run);
        self::assertStringContainsString('::warning::', $run);
    }

    public function test_off_mode_omits_the_stage_and_the_deploy_dependency(): void
    {
        $definition = new PipelineDefinition(staticAnalysisMode: QualityMode::Off);

        self::assertNull($this->stageById($definition, 'analyse'));

        $deploy = $this->stageById($definition, 'deploy');
        self::assertNotNull($deploy);
        self::assertNotContains('analyse', $deploy->needs);
    }

    public function test_stage_ordering_follows_catalog(): void
    {
        $builder = new PipelineBuilder(new StageGate());
        $pipeline = $builder->build(new PipelineDefinition());

        $kinds = array_map(fn ($s) => $s->kind, $pipeline->stages);

        $testIdx = array_search(StageKind::Test, $kinds, true);
        $analyseIdx = array_search(StageKind::StaticAnalysis, $kinds, true);
        $agnosticIdx = array_search(StageKind::Agnosticism, $kinds, true);
        $deployIdx = array_search(StageKind::Deploy, $kinds, true);

        $this->assertLessThan($analyseIdx, $testIdx);
        $this->assertLessThan($agnosticIdx, $testIdx);
        $this->assertLessThan($deployIdx, $analyseIdx);
    }

    public function test_deploy_stage_calls_doctor_and_deploy(): void
    {
        $builder = new PipelineBuilder(new StageGate());
        $pipeline = $builder->build(new PipelineDefinition());

        $deploy = null;
        foreach ($pipeline->stages as $stage) {
            if ($stage->kind === StageKind::Deploy) {
                $deploy = $stage;
                break;
            }
        }

        $this->assertNotNull($deploy);

        $stepNames = array_map(fn ($s) => $s->name, $deploy->steps);
        $hasDoctor = false;
        $hasDeploy = false;

        foreach ($deploy->steps as $step) {
            if ($step instanceof CommandStep && str_contains($step->run, 'deploy:doctor')) {
                $hasDoctor = true;
            }
            if ($step instanceof CommandStep && str_contains($step->run, 'deploy --env')) {
                $hasDeploy = true;
            }
        }

        $this->assertTrue($hasDoctor, 'Deploy stage must call deploy:doctor');
        $this->assertTrue($hasDeploy, 'Deploy stage must call deploy');
    }

    public function test_deploy_stage_has_environment_matrix(): void
    {
        $builder = new PipelineBuilder(new StageGate());
        $pipeline = $builder->build(new PipelineDefinition(environments: ['staging', 'production']));

        $deploy = null;
        foreach ($pipeline->stages as $stage) {
            if ($stage->kind === StageKind::Deploy) {
                $deploy = $stage;
                break;
            }
        }

        $this->assertNotNull($deploy);
        $this->assertNotNull($deploy->matrix);
        $this->assertSame('environment', $deploy->matrix->axisName);
        $this->assertCount(2, $deploy->matrix->values);
    }

    public function test_deploy_stage_has_condition_for_main_branch_push(): void
    {
        $builder = new PipelineBuilder(new StageGate());
        $pipeline = $builder->build(new PipelineDefinition());

        $deploy = null;
        foreach ($pipeline->stages as $stage) {
            if ($stage->kind === StageKind::Deploy) {
                $deploy = $stage;
                break;
            }
        }

        $this->assertNotNull($deploy);
        $this->assertNotNull($deploy->condition);
        $this->assertStringContainsString('main', $deploy->condition);
        $this->assertStringContainsString('push', $deploy->condition);
    }

    public function test_split_packages_produce_split_stage(): void
    {
        $builder = new PipelineBuilder(new StageGate());
        $pipeline = $builder->build(
            new PipelineDefinition(),
            [new SplitPackage('packages/Vortos/src/Domain', 'vortos-domain')],
        );

        $splitStage = null;
        foreach ($pipeline->stages as $stage) {
            if ($stage->kind === StageKind::Split) {
                $splitStage = $stage;
                break;
            }
        }

        $this->assertNotNull($splitStage);
        $this->assertNotNull($splitStage->matrix);
        $this->assertSame('package', $splitStage->matrix->axisName);
    }

    public function test_empty_split_packages_omit_split_stage(): void
    {
        $builder = new PipelineBuilder(new StageGate());
        $pipeline = $builder->build(new PipelineDefinition(), []);

        foreach ($pipeline->stages as $stage) {
            $this->assertNotSame(StageKind::Split, $stage->kind);
        }
    }

    public function test_triggers_include_push_and_pull_request(): void
    {
        $builder = new PipelineBuilder(new StageGate());
        $pipeline = $builder->build(new PipelineDefinition());

        $events = array_map(fn ($t) => $t->event, $pipeline->triggers);
        $this->assertContains(TriggerEvent::Push, $events);
        $this->assertContains(TriggerEvent::PullRequest, $events);
    }

    public function test_push_trigger_targets_main_branch(): void
    {
        $builder = new PipelineBuilder(new StageGate());
        $pipeline = $builder->build(new PipelineDefinition());

        foreach ($pipeline->triggers as $trigger) {
            if ($trigger->event === TriggerEvent::Push) {
                $this->assertContains('main', $trigger->branches);
                return;
            }
        }

        $this->fail('No push trigger found');
    }

    public function test_permissions_are_read_only(): void
    {
        $builder = new PipelineBuilder(new StageGate());
        $pipeline = $builder->build(new PipelineDefinition());

        $this->assertSame(['contents' => 'read'], $pipeline->permissions->toArray());
    }

    public function test_concurrency_group_is_set(): void
    {
        $builder = new PipelineBuilder(new StageGate());
        $pipeline = $builder->build(new PipelineDefinition());

        $this->assertNotNull($pipeline->concurrencyGroup);
        $this->assertTrue($pipeline->concurrencyCancelInProgress);
    }

    public function test_pipeline_name_is_ci(): void
    {
        $builder = new PipelineBuilder(new StageGate());
        $pipeline = $builder->build(new PipelineDefinition());

        $this->assertSame('CI', $pipeline->name);
    }

    public function test_deploy_stage_needs_tests_analyse_agnosticism(): void
    {
        $builder = new PipelineBuilder(new StageGate());
        $pipeline = $builder->build(new PipelineDefinition());

        $deploy = null;
        foreach ($pipeline->stages as $stage) {
            if ($stage->kind === StageKind::Deploy) {
                $deploy = $stage;
                break;
            }
        }

        $this->assertNotNull($deploy);
        $this->assertContains('tests', $deploy->needs);
        $this->assertContains('analyse', $deploy->needs);
        $this->assertContains('agnosticism', $deploy->needs);
    }

    public function test_split_stage_needs_tests(): void
    {
        $builder = new PipelineBuilder(new StageGate());
        $pipeline = $builder->build(
            new PipelineDefinition(),
            [new SplitPackage('packages/Vortos/src/Domain', 'vortos-domain')],
        );

        $split = null;
        foreach ($pipeline->stages as $stage) {
            if ($stage->kind === StageKind::Split) {
                $split = $stage;
                break;
            }
        }

        $this->assertNotNull($split);
        $this->assertContains('tests', $split->needs);
    }
}
