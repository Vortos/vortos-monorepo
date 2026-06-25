<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Conformance;

use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Driver\GitHubActions\GitHubActionsEmitter;
use Vortos\Pipeline\Driver\GitHubActions\GitHubWorkflowMapper;
use Vortos\Pipeline\Driver\GitHubActions\SplitWorkflowGenerator;
use Vortos\Pipeline\Driver\GitHubActions\WorkflowYamlWriter;
use Vortos\Pipeline\Emitter\Capability\EmitterCapability;
use Vortos\Pipeline\Emitter\PipelineEmitterInterface;
use Vortos\Pipeline\Testing\PipelineEmitterConformanceTestCase;

final class GitHubActionsEmitterConformanceTest extends PipelineEmitterConformanceTestCase
{
    protected function createEmitter(): PipelineEmitterInterface
    {
        return new GitHubActionsEmitter(
            new GitHubWorkflowMapper(),
            new SplitWorkflowGenerator(),
            new WorkflowYamlWriter(),
            new PipelineDefinition(),
        );
    }

    protected function expectedKey(): string
    {
        return 'github';
    }

    public function test_honestly_reports_unsupported_gitlab(): void
    {
        $descriptor = $this->createEmitter()->capabilities();
        $this->assertHonestlyUnsupported($descriptor, EmitterCapability::GitlabCi);
    }

    public function test_honestly_reports_unsupported_oidc(): void
    {
        $descriptor = $this->createEmitter()->capabilities();
        $this->assertHonestlyUnsupported($descriptor, EmitterCapability::Oidc);
    }

    public function test_honestly_reports_unsupported_reusable_workflows(): void
    {
        $descriptor = $this->createEmitter()->capabilities();
        $this->assertHonestlyUnsupported($descriptor, EmitterCapability::ReusableWorkflows);
    }

    public function test_reports_supported_github_actions(): void
    {
        $descriptor = $this->createEmitter()->capabilities();
        $this->assertTrue($descriptor->supports(EmitterCapability::GithubActions));
    }

    public function test_reports_supported_matrix(): void
    {
        $descriptor = $this->createEmitter()->capabilities();
        $this->assertTrue($descriptor->supports(EmitterCapability::Matrix));
    }

    public function test_reports_supported_sha_pinning(): void
    {
        $descriptor = $this->createEmitter()->capabilities();
        $this->assertTrue($descriptor->supports(EmitterCapability::ShaPinning));
    }
}
