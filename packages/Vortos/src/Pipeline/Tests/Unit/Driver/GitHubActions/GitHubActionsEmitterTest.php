<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Driver\GitHubActions;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Builder\KnownActionFactory;
use Vortos\Pipeline\Builder\PipelineBuilder;
use Vortos\Pipeline\Builder\StageGate;
use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Driver\GitHubActions\GitHubActionsEmitter;
use Vortos\Pipeline\Driver\GitHubActions\GitHubWorkflowMapper;
use Vortos\Pipeline\Driver\GitHubActions\SplitWorkflowGenerator;
use Vortos\Pipeline\Driver\GitHubActions\WorkflowYamlWriter;
use Vortos\Pipeline\Emitter\Capability\EmitterCapability;
use Vortos\Pipeline\Model\ActionStep;
use Vortos\Pipeline\Model\CommandStep;
use Vortos\Pipeline\Model\Matrix;
use Vortos\Pipeline\Model\Permissions;
use Vortos\Pipeline\Model\Pipeline;
use Vortos\Pipeline\Model\SplitPackage;
use Vortos\Pipeline\Model\Stage;
use Vortos\Pipeline\Model\StageKind;
use Vortos\Pipeline\Model\Trigger;
use Vortos\Pipeline\Model\TriggerEvent;

final class GitHubActionsEmitterTest extends TestCase
{
    public function test_capabilities_report_github_actions_true(): void
    {
        $emitter = $this->createEmitter();
        $this->assertTrue($emitter->capabilities()->supports(EmitterCapability::GithubActions));
    }

    public function test_capabilities_report_gitlab_ci_false(): void
    {
        $emitter = $this->createEmitter();
        $this->assertFalse($emitter->capabilities()->supports(EmitterCapability::GitlabCi));
    }

    public function test_capabilities_report_sha_pinning_true(): void
    {
        $emitter = $this->createEmitter();
        $this->assertTrue($emitter->capabilities()->supports(EmitterCapability::ShaPinning));
    }

    public function test_capabilities_report_matrix_true(): void
    {
        $emitter = $this->createEmitter();
        $this->assertTrue($emitter->capabilities()->supports(EmitterCapability::Matrix));
    }

    public function test_capabilities_report_oidc_false(): void
    {
        $emitter = $this->createEmitter();
        $this->assertFalse($emitter->capabilities()->supports(EmitterCapability::Oidc));
    }

    public function test_capabilities_report_reusable_workflows_false(): void
    {
        $emitter = $this->createEmitter();
        $this->assertFalse($emitter->capabilities()->supports(EmitterCapability::ReusableWorkflows));
    }

    public function test_emit_produces_ci_yml(): void
    {
        $emitter = $this->createEmitter();
        $pipeline = $this->buildPipeline();

        $artifacts = $emitter->emit($pipeline);
        $ci = $artifacts->byPath('.github/workflows/ci.yml');

        $this->assertNotNull($ci);
        $this->assertNotSame('', $ci->contents);
    }

    public function test_emit_produces_split_yml_when_split_stage_present(): void
    {
        $emitter = $this->createEmitter();
        $pipeline = $this->buildPipelineWithSplit();

        $artifacts = $emitter->emit($pipeline);
        $split = $artifacts->byPath('.github/workflows/split.yml');

        $this->assertNotNull($split);
        $this->assertNotSame('', $split->contents);
    }

    public function test_emit_no_split_without_split_stage(): void
    {
        $emitter = $this->createEmitter();
        $pipeline = $this->buildPipeline();

        $artifacts = $emitter->emit($pipeline);
        $split = $artifacts->byPath('.github/workflows/split.yml');

        $this->assertNull($split);
    }

    public function test_deploy_stage_contains_doctor_and_deploy_commands(): void
    {
        $emitter = $this->createEmitter();
        $pipeline = $this->buildPipeline();
        $artifacts = $emitter->emit($pipeline);

        $ci = $artifacts->byPath('.github/workflows/ci.yml');
        $this->assertNotNull($ci);

        $this->assertStringContainsString('deploy:doctor', $ci->contents);
        $this->assertStringContainsString('deploy --env', $ci->contents);
    }

    public function test_all_uses_contain_40_char_sha(): void
    {
        $emitter = $this->createEmitter();
        $pipeline = $this->buildPipelineWithSplit();
        $artifacts = $emitter->emit($pipeline);

        foreach ($artifacts as $artifact) {
            preg_match_all('/uses:\s*(.+)/', $artifact->contents, $matches);
            foreach ($matches[1] as $usesLine) {
                $this->assertMatchesRegularExpression(
                    '/[0-9a-f]{40}/',
                    $usesLine,
                    sprintf('uses: line must contain a 40-char SHA: %s', $usesLine),
                );
            }
        }
    }

    private function createEmitter(): GitHubActionsEmitter
    {
        return new GitHubActionsEmitter(
            new GitHubWorkflowMapper(),
            new SplitWorkflowGenerator(),
            new WorkflowYamlWriter(),
            new PipelineDefinition(),
        );
    }

    private function buildPipeline(): Pipeline
    {
        $builder = new PipelineBuilder(new StageGate());
        return $builder->build(new PipelineDefinition());
    }

    private function buildPipelineWithSplit(): Pipeline
    {
        $builder = new PipelineBuilder(new StageGate());
        return $builder->build(
            new PipelineDefinition(),
            [
                new SplitPackage('packages/Vortos/src/Domain', 'vortos-domain'),
                new SplitPackage('packages/Vortos/src/Foundation', 'vortos-foundation'),
            ],
        );
    }
}
