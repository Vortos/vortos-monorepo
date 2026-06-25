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

final class PinnedActionArchTest extends TestCase
{
    public function test_no_floating_tag_in_any_emitted_artifact(): void
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

        foreach ($artifacts as $artifact) {
            preg_match_all('/uses:\s+(.+)$/m', $artifact->contents, $matches);

            foreach ($matches[1] as $ref) {
                $ref = trim($ref, "'\" ");

                $atPos = strpos($ref, '@');
                $this->assertNotFalse($atPos, 'uses: reference missing @: ' . $ref);

                $refPart = substr($ref, $atPos + 1);
                $refPart = explode(' ', $refPart)[0];

                $this->assertMatchesRegularExpression(
                    '/^[0-9a-f]{40}$/',
                    $refPart,
                    'uses: reference after @ must be exactly 40 hex chars (SHA), got: ' . $refPart,
                );
            }
        }
    }
}
