<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Builder\PipelineBuilder;
use Vortos\Pipeline\Builder\StageGate;
use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Driver\GitHubActions\GitHubActionsEmitter;
use Vortos\Pipeline\Driver\GitHubActions\GitHubWorkflowMapper;
use Vortos\Pipeline\Driver\GitHubActions\SplitWorkflowGenerator;
use Vortos\Pipeline\Driver\GitHubActions\WorkflowYamlWriter;
use Vortos\Pipeline\Model\ServiceContainer;

/**
 * Locks in the pipeline configuration surface added for upstream P1-3/P1-4: the emitted workflow
 * honours a configured filename/name, injects app-declared test steps and service containers, and
 * uses the configured test/analyse commands — so the generated pipeline can actually be the CI.
 */
final class PipelineConfigurationSurfaceTest extends TestCase
{
    public function test_emitted_workflow_reflects_full_configuration(): void
    {
        $definition = new PipelineDefinition(
            workflowFilename: 'deploy.yml',
            workflowName: 'Deploy',
            testCommand: './vendor/bin/phpunit --testsuite=Unit',
            analyseCommand: './vendor/bin/phpstan analyse --level=9',
            testServiceContainers: [
                new ServiceContainer(
                    name: 'postgres',
                    image: 'postgres:18-alpine',
                    ports: ['5432:5432'],
                    env: ['POSTGRES_PASSWORD' => 'test'],
                    options: ['--health-cmd=pg_isready'],
                ),
            ],
            testSteps: [
                ['name' => 'Run migrations', 'run' => 'bin/console vortos:migrate --no-interaction'],
            ],
        );

        $emitter = new GitHubActionsEmitter(
            new GitHubWorkflowMapper(),
            new SplitWorkflowGenerator(),
            new WorkflowYamlWriter(),
            $definition,
        );

        $pipeline = (new PipelineBuilder(new StageGate()))->build($definition);
        $artifacts = iterator_to_array($emitter->emit($pipeline));

        $ci = null;
        foreach ($artifacts as $artifact) {
            if ($artifact->relativePath === '.github/workflows/deploy.yml') {
                $ci = $artifact;
                break;
            }
        }

        self::assertNotNull($ci, 'workflow must be emitted to the configured filename deploy.yml');
        $yaml = $ci->contents;

        self::assertStringContainsString('name: Deploy', $yaml);
        self::assertStringContainsString('postgres:18-alpine', $yaml);
        self::assertStringContainsString('Run migrations', $yaml);
        self::assertStringContainsString('vortos:migrate', $yaml);
        self::assertStringContainsString('phpunit --testsuite=Unit', $yaml);
        self::assertStringContainsString('phpstan analyse --level=9', $yaml);
    }

    public function test_defaults_preserve_legacy_ci_yml(): void
    {
        $definition = new PipelineDefinition();
        $emitter = new GitHubActionsEmitter(
            new GitHubWorkflowMapper(),
            new SplitWorkflowGenerator(),
            new WorkflowYamlWriter(),
            $definition,
        );

        $pipeline = (new PipelineBuilder(new StageGate()))->build($definition);
        $paths = array_map(static fn ($a): string => $a->relativePath, iterator_to_array($emitter->emit($pipeline)));

        self::assertContains('.github/workflows/ci.yml', $paths);
    }
}
