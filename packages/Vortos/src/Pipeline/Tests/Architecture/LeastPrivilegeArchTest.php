<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Builder\PipelineBuilder;
use Vortos\Pipeline\Builder\StageGate;
use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Driver\GitHubActions\GitHubActionsEmitter;
use Vortos\Pipeline\Driver\GitHubActions\GitHubWorkflowMapper;
use Vortos\Pipeline\Driver\GitHubActions\SplitWorkflowGenerator;
use Vortos\Pipeline\Driver\GitHubActions\WorkflowYamlWriter;
use Vortos\Pipeline\Model\SplitPackage;

final class LeastPrivilegeArchTest extends TestCase
{
    public function test_ci_workflow_has_workflow_level_permissions(): void
    {
        $ci = $this->emitCi();

        $this->assertStringContainsString(
            'permissions:',
            $ci,
            'CI workflow must declare a permissions block',
        );
    }

    public function test_split_workflow_has_workflow_level_permissions(): void
    {
        $split = $this->emitSplit();

        $this->assertStringContainsString(
            'permissions:',
            $split,
            'Split workflow must declare a permissions block',
        );
    }

    public function test_ci_workflow_level_permissions_are_read_only(): void
    {
        $ci = $this->emitCi();

        $lines = explode("\n", $ci);
        $foundTopLevelPermissions = false;

        for ($i = 0; $i < \count($lines); $i++) {
            if (preg_match('/^permissions:$/', $lines[$i])) {
                $foundTopLevelPermissions = true;
                $nextLine = $lines[$i + 1] ?? '';
                $this->assertStringContainsString(
                    'read',
                    $nextLine,
                    'Workflow-level permissions must default to read',
                );
                break;
            }
        }

        $this->assertTrue($foundTopLevelPermissions, 'Must have a top-level permissions: block');
    }

    public function test_split_workflow_permissions_are_read_only(): void
    {
        $split = $this->emitSplit();

        $lines = explode("\n", $split);

        for ($i = 0; $i < \count($lines); $i++) {
            if (preg_match('/^permissions:$/', $lines[$i])) {
                $nextLine = $lines[$i + 1] ?? '';
                $this->assertStringContainsString(
                    'read',
                    $nextLine,
                    'Split workflow-level permissions must be read-only',
                );

                return;
            }
        }

        $this->fail('Split workflow must have a top-level permissions: block');
    }

    public function test_every_ci_job_has_permissions_or_inherits(): void
    {
        $mapper = new GitHubWorkflowMapper();
        $definition = new PipelineDefinition();
        $gate = new StageGate();
        $builder = new PipelineBuilder($gate);
        $pipeline = $builder->build($definition);

        $workflowArray = $mapper->map($pipeline);

        $this->assertArrayHasKey('permissions', $workflowArray, 'Workflow must have top-level permissions');

        foreach ($workflowArray['jobs'] as $jobId => $job) {
            $hasOwnPermissions = isset($job['permissions']);
            $workflowHasPermissions = isset($workflowArray['permissions']);

            $this->assertTrue(
                $hasOwnPermissions || $workflowHasPermissions,
                'Job "' . $jobId . '" must have its own permissions or inherit from workflow-level',
            );
        }
    }

    private function emitCi(): string
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
        $this->assertNotNull($ci, 'CI workflow artifact must exist');

        return $ci->contents;
    }

    private function emitSplit(): string
    {
        $definition = new PipelineDefinition();

        $gate = new StageGate();
        $builder = new PipelineBuilder($gate);

        $pipeline = $builder->build($definition, [
            new SplitPackage('packages/Vortos/src/Domain', 'vortos-domain'),
        ]);

        $emitter = new GitHubActionsEmitter(
            new GitHubWorkflowMapper(),
            new SplitWorkflowGenerator(),
            new WorkflowYamlWriter(),
            $definition,
        );

        $artifacts = $emitter->emit($pipeline);
        $split = $artifacts->byPath('.github/workflows/split.yml');
        $this->assertNotNull($split, 'Split workflow artifact must exist');

        return $split->contents;
    }
}
