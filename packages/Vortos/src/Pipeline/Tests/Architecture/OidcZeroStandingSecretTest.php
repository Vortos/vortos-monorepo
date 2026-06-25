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

final class OidcZeroStandingSecretTest extends TestCase
{
    public function test_no_static_credential_in_build_workflow_with_oidc(): void
    {
        $def = new PipelineDefinition(imageRepository: 'ghcr.io/org/app', oidc: true);
        $builder = new PipelineBuilder(new StageGate());
        $pipeline = $builder->build($def);

        $emitter = new GitHubActionsEmitter(
            new GitHubWorkflowMapper(),
            new SplitWorkflowGenerator(),
            new WorkflowYamlWriter(),
            $def,
        );

        $artifacts = $emitter->emit($pipeline);
        foreach ($artifacts as $artifact) {
            if (!str_contains($artifact->relativePath, 'ci.yml')) {
                continue;
            }

            $yaml = $artifact->contents;
            $forbidden = [
                'secrets.DOCKER_PASSWORD',
                'secrets.DOCKER_PAT',
                'secrets.REGISTRY_PASSWORD',
                'secrets.REGISTRY_PAT',
                'secrets.SSH_PRIVATE_KEY',
                'secrets.SSH_KEY',
                'secrets.DEPLOY_KEY',
            ];

            foreach ($forbidden as $secret) {
                $this->assertStringNotContainsString(
                    $secret,
                    $yaml,
                    sprintf('Generated workflow must not reference static credential "%s" when OIDC is on', $secret),
                );
            }

            $this->assertStringContainsString('id-token', $yaml, 'OIDC workflow must declare id-token permission');
        }
    }

    public function test_build_and_deploy_use_only_github_token_or_oidc(): void
    {
        $def = new PipelineDefinition(imageRepository: 'ghcr.io/org/app', oidc: true);
        $builder = new PipelineBuilder(new StageGate());
        $pipeline = $builder->build($def);

        $mapper = new GitHubWorkflowMapper();
        $array = $mapper->map($pipeline);

        $allSteps = [];
        foreach ($array['jobs'] as $job) {
            foreach ($job['steps'] as $step) {
                $allSteps[] = $step;
            }
        }

        foreach ($allSteps as $step) {
            $text = json_encode($step);
            if (preg_match_all('/secrets\.(\w+)/', $text, $matches)) {
                foreach ($matches[1] as $secretName) {
                    $this->assertContains(
                        $secretName,
                        ['GITHUB_TOKEN'],
                        sprintf('Only GITHUB_TOKEN is allowed, found secrets.%s', $secretName),
                    );
                }
            }
        }
    }
}
