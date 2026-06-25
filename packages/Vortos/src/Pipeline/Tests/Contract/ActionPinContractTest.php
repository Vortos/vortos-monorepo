<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Builder\KnownActionFactory;
use Vortos\Pipeline\Builder\PipelineBuilder;
use Vortos\Pipeline\Builder\StageGate;
use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Driver\GitHubActions\GitHubActionsEmitter;
use Vortos\Pipeline\Driver\GitHubActions\GitHubWorkflowMapper;
use Vortos\Pipeline\Driver\GitHubActions\SplitWorkflowGenerator;
use Vortos\Pipeline\Driver\GitHubActions\WorkflowYamlWriter;
use Vortos\Pipeline\Model\SplitPackage;

final class ActionPinContractTest extends TestCase
{
    public function test_all_uses_references_in_emitted_artifacts_are_sha_pinned(): void
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

        foreach ($artifacts as $artifact) {
            preg_match_all('/uses:\s+(.+)$/m', $artifact->contents, $matches);

            $this->assertNotEmpty(
                $matches[1],
                'Expected at least one uses: reference in ' . $artifact->relativePath,
            );

            foreach ($matches[1] as $ref) {
                $ref = trim($ref, "'\" ");

                $this->assertMatchesRegularExpression(
                    '/^[a-zA-Z0-9_.-]+\/[a-zA-Z0-9_.-]+@[0-9a-f]{40}/',
                    $ref,
                    'uses: reference must be in owner/repo@sha40hex format: ' . $ref,
                );

                $this->assertDoesNotMatchRegularExpression(
                    '/@v\d/',
                    $ref,
                    'uses: reference must not use a floating tag: ' . $ref,
                );
            }
        }
    }

    public function test_known_actions_all_have_40_hex_sha(): void
    {
        foreach (KnownActionFactory::all() as $action) {
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{40}$/',
                $action->sha,
                'KnownAction ' . $action->owner . '/' . $action->repo . ' must have a 40-hex SHA',
            );
        }
    }

    public function test_known_actions_all_have_version_comments(): void
    {
        foreach (KnownActionFactory::all() as $action) {
            $this->assertNotSame(
                '',
                $action->versionComment,
                'KnownAction ' . $action->owner . '/' . $action->repo . ' must have a version comment',
            );
        }
    }

    public function test_known_actions_map_is_complete(): void
    {
        $all = KnownActionFactory::all();
        $this->assertGreaterThanOrEqual(4, \count($all));

        $names = array_map(
            static fn ($a): string => $a->owner . '/' . $a->repo,
            $all,
        );

        $this->assertContains('actions/checkout', $names);
        $this->assertContains('shivammathur/setup-php', $names);
        $this->assertContains('actions/setup-node', $names);
        $this->assertContains('danharrin/monorepo-split-github-action', $names);
    }
}
