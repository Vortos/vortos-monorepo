<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Builder\PipelineBuilder;
use Vortos\Pipeline\Builder\StageGate;
use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Driver\GitHubActions\GitHubActionsEmitter;
use Vortos\Pipeline\Driver\GitHubActions\GitHubWorkflowMapper;
use Vortos\Pipeline\Driver\GitHubActions\SplitWorkflowGenerator;
use Vortos\Pipeline\Driver\GitHubActions\WorkflowYamlWriter;
use Vortos\Pipeline\Model\SplitPackage;

final class PipelineGenerateIntegrationTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/vortos-pipeline-test-' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    public function test_end_to_end_emits_parseable_ci_workflow(): void
    {
        $definition = new PipelineDefinition();
        $gate = new StageGate();
        $builder = new PipelineBuilder($gate);
        $pipeline = $builder->build($definition);

        $emitter = new GitHubActionsEmitter(
            new GitHubWorkflowMapper(),
            new SplitWorkflowGenerator(),
            new WorkflowYamlWriter(),
            $definition,
        );

        $artifacts = $emitter->emit($pipeline);

        $ci = $artifacts->byPath('.github/workflows/ci.yml');
        $this->assertNotNull($ci, 'ci.yml must be emitted');

        $ciPath = $this->tempDir . '/.github/workflows/ci.yml';
        mkdir(\dirname($ciPath), 0755, true);
        file_put_contents($ciPath, $ci->contents);

        $written = file_get_contents($ciPath);
        $this->assertSame($ci->contents, $written);

        $this->assertStringContainsString('name:', $written);
        $this->assertStringContainsString("'on':", $written);
        $this->assertStringContainsString('jobs:', $written);
    }

    public function test_end_to_end_emits_both_ci_and_split_workflows(): void
    {
        $definition = new PipelineDefinition();
        $gate = new StageGate();
        $builder = new PipelineBuilder($gate);

        $splitPackages = [
            new SplitPackage('packages/Vortos/src/Domain', 'vortos-domain'),
            new SplitPackage('packages/Vortos/src/Foundation', 'vortos-foundation'),
        ];

        $pipeline = $builder->build($definition, $splitPackages);

        $emitter = new GitHubActionsEmitter(
            new GitHubWorkflowMapper(),
            new SplitWorkflowGenerator(),
            new WorkflowYamlWriter(),
            $definition,
        );

        $artifacts = $emitter->emit($pipeline);

        $this->assertNotNull($artifacts->byPath('.github/workflows/ci.yml'), 'ci.yml must be present');
        $this->assertNotNull($artifacts->byPath('.github/workflows/split.yml'), 'split.yml must be present');
    }

    public function test_ci_workflow_stage_ordering_is_correct(): void
    {
        $definition = new PipelineDefinition();
        $gate = new StageGate();
        $builder = new PipelineBuilder($gate);
        $pipeline = $builder->build($definition);

        $stageIds = [];
        foreach ($pipeline->stages as $stage) {
            $stageIds[] = $stage->id;
        }

        $testIdx = array_search('tests', $stageIds, true);
        $analyseIdx = array_search('analyse', $stageIds, true);
        $agnosticismIdx = array_search('agnosticism', $stageIds, true);
        $deployIdx = array_search('deploy', $stageIds, true);

        $this->assertNotFalse($testIdx, 'tests stage must exist');
        $this->assertNotFalse($analyseIdx, 'analyse stage must exist');
        $this->assertNotFalse($agnosticismIdx, 'agnosticism stage must exist');
        $this->assertNotFalse($deployIdx, 'deploy stage must exist');

        $this->assertLessThan($analyseIdx, $testIdx, 'tests must come before analyse');
        $this->assertLessThan($deployIdx, $testIdx, 'tests must come before deploy');
    }

    public function test_deploy_stage_depends_on_required_stages(): void
    {
        $definition = new PipelineDefinition();
        $gate = new StageGate();
        $builder = new PipelineBuilder($gate);
        $pipeline = $builder->build($definition);

        $deployStage = $pipeline->stageById('deploy');
        $this->assertNotNull($deployStage);

        $this->assertContains('tests', $deployStage->needs);
        $this->assertContains('analyse', $deployStage->needs);
        $this->assertContains('agnosticism', $deployStage->needs);
    }

    public function test_emitted_yaml_is_deterministic(): void
    {
        $definition = new PipelineDefinition();
        $gate = new StageGate();
        $builder = new PipelineBuilder($gate);
        $pipeline = $builder->build($definition);

        $emitter = new GitHubActionsEmitter(
            new GitHubWorkflowMapper(),
            new SplitWorkflowGenerator(),
            new WorkflowYamlWriter(),
            $definition,
        );

        $first = $emitter->emit($pipeline);
        $second = $emitter->emit($pipeline);

        $this->assertCount(\count($first), $second);

        foreach ($first as $i => $artifact) {
            $this->assertSame(
                $artifact->contents,
                $second->artifacts[$i]->contents,
                'Emitted YAML must be byte-identical across runs for ' . $artifact->relativePath,
            );
        }
    }

    public function test_pipeline_with_environments_uses_matrix(): void
    {
        $definition = new PipelineDefinition(
            environments: ['staging', 'production'],
        );

        $gate = new StageGate();
        $builder = new PipelineBuilder($gate);
        $pipeline = $builder->build($definition);

        $deployStage = $pipeline->stageById('deploy');
        $this->assertNotNull($deployStage);
        $this->assertNotNull($deployStage->matrix);
        $this->assertSame('environment', $deployStage->matrix->axisName);
        $this->assertCount(2, $deployStage->matrix->values);
    }

    public function test_gated_stages_are_reported(): void
    {
        $gate = new StageGate();
        $builder = new PipelineBuilder($gate);

        $definition = new PipelineDefinition();
        $builder->build($definition);

        $gated = $gate->gatedStages();
        $this->assertNotEmpty($gated, 'Some future stages should be gated');
    }

    public function test_artifacts_write_to_disk_and_read_back(): void
    {
        $definition = new PipelineDefinition();
        $gate = new StageGate();
        $builder = new PipelineBuilder($gate);
        $pipeline = $builder->build($definition);

        $emitter = new GitHubActionsEmitter(
            new GitHubWorkflowMapper(),
            new SplitWorkflowGenerator(),
            new WorkflowYamlWriter(),
            $definition,
        );

        $artifacts = $emitter->emit($pipeline);

        foreach ($artifacts as $artifact) {
            $path = $this->tempDir . '/' . $artifact->relativePath;
            $dir = \dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($path, $artifact->contents);

            $this->assertFileExists($path);
            $this->assertSame($artifact->contents, file_get_contents($path));
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($dir);
    }
}
