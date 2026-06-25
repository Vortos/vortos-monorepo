<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Driver\GitHubActions;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Driver\GitHubActions\GitHubActionsEmitter;
use Vortos\Pipeline\Driver\GitHubActions\GitHubWorkflowMapper;
use Vortos\Pipeline\Driver\GitHubActions\SplitWorkflowGenerator;
use Vortos\Pipeline\Driver\GitHubActions\WorkflowYamlWriter;
use Vortos\Pipeline\Emitter\Capability\EmitterCapability;

final class GitHubActionsEmitterCapabilityTest extends TestCase
{
    private function emitter(PipelineDefinition $def): GitHubActionsEmitter
    {
        return new GitHubActionsEmitter(
            new GitHubWorkflowMapper(),
            new SplitWorkflowGenerator(),
            new WorkflowYamlWriter(),
            $def,
        );
    }

    public function test_oidc_capability_true_when_oidc_enabled(): void
    {
        $emitter = $this->emitter(new PipelineDefinition(imageRepository: 'ghcr.io/org/app', oidc: true));
        $caps = $emitter->capabilities();

        $this->assertTrue($caps->supports(EmitterCapability::Oidc->value));
    }

    public function test_oidc_capability_false_when_oidc_disabled(): void
    {
        $emitter = $this->emitter(new PipelineDefinition());
        $caps = $emitter->capabilities();

        $this->assertFalse($caps->supports(EmitterCapability::Oidc->value));
    }

    public function test_build_native_arch_true_when_image_repo_set(): void
    {
        $emitter = $this->emitter(new PipelineDefinition(imageRepository: 'ghcr.io/org/app'));
        $caps = $emitter->capabilities();

        $this->assertTrue($caps->supports(EmitterCapability::BuildNativeArch->value));
    }

    public function test_build_native_arch_false_when_no_image_repo(): void
    {
        $emitter = $this->emitter(new PipelineDefinition());
        $caps = $emitter->capabilities();

        $this->assertFalse($caps->supports(EmitterCapability::BuildNativeArch->value));
    }

    public function test_sha_pinning_always_true(): void
    {
        $emitter = $this->emitter(new PipelineDefinition());
        $caps = $emitter->capabilities();

        $this->assertTrue($caps->supports(EmitterCapability::ShaPinning->value));
    }
}
