<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Driver\GitHubActions;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Driver\GitHubActions\SplitWorkflowGenerator;
use Vortos\Pipeline\Model\SplitPackage;

final class SplitWorkflowGeneratorTest extends TestCase
{
    private SplitWorkflowGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new SplitWorkflowGenerator();
    }

    public function test_generates_workflow_with_correct_name(): void
    {
        $result = $this->generate();
        $this->assertSame('Monorepo Split', $result['name']);
    }

    public function test_generates_correct_matrix(): void
    {
        $packages = [
            new SplitPackage('packages/Vortos/src/Domain', 'vortos-domain'),
            new SplitPackage('packages/Vortos/src/Foundation', 'vortos-foundation'),
        ];

        $result = $this->generator->generate($packages, new PipelineDefinition());

        $matrix = $result['jobs']['split']['strategy']['matrix']['package'];
        $this->assertCount(2, $matrix);
        $this->assertSame('packages/Vortos/src/Domain', $matrix[0]['local_path']);
        $this->assertSame('vortos-domain', $matrix[0]['split_repository']);
        $this->assertSame('packages/Vortos/src/Foundation', $matrix[1]['local_path']);
        $this->assertSame('vortos-foundation', $matrix[1]['split_repository']);
    }

    public function test_sha_pins_all_actions(): void
    {
        $result = $this->generate();

        $allSteps = [];
        foreach ($result['jobs'] as $job) {
            foreach ($job['steps'] as $step) {
                if (isset($step['uses'])) {
                    $allSteps[] = $step['uses']->value;
                }
            }
        }

        $this->assertNotEmpty($allSteps);
        foreach ($allSteps as $uses) {
            $this->assertMatchesRegularExpression(
                '/[0-9a-f]{40}/',
                $uses,
                sprintf('Action must be SHA-pinned: %s', $uses),
            );
        }
    }

    public function test_includes_tests_job(): void
    {
        $result = $this->generate();
        $this->assertArrayHasKey('tests', $result['jobs']);
    }

    public function test_benchmark_included_when_enabled(): void
    {
        $def = new PipelineDefinition(benchmark: true);
        $result = $this->generator->generate($this->defaultPackages(), $def);

        $this->assertArrayHasKey('benchmark', $result['jobs']);
    }

    public function test_benchmark_excluded_when_disabled(): void
    {
        $def = new PipelineDefinition(benchmark: false);
        $result = $this->generator->generate($this->defaultPackages(), $def);

        $this->assertArrayNotHasKey('benchmark', $result['jobs']);
    }

    public function test_ui_build_included_when_enabled(): void
    {
        $def = new PipelineDefinition(uiBuild: true, nodeVersion: '20');
        $result = $this->generator->generate($this->defaultPackages(), $def);

        $this->assertArrayHasKey('ui-build', $result['jobs']);
    }

    public function test_ui_build_excluded_when_disabled(): void
    {
        $def = new PipelineDefinition(uiBuild: false);
        $result = $this->generator->generate($this->defaultPackages(), $def);

        $this->assertArrayNotHasKey('ui-build', $result['jobs']);
    }

    public function test_ui_build_excluded_without_node_version(): void
    {
        $def = new PipelineDefinition(uiBuild: true, nodeVersion: null);
        $result = $this->generator->generate($this->defaultPackages(), $def);

        $this->assertArrayNotHasKey('ui-build', $result['jobs']);
    }

    public function test_split_needs_includes_ui_build_when_enabled(): void
    {
        $def = new PipelineDefinition(uiBuild: true, nodeVersion: '20');
        $result = $this->generator->generate($this->defaultPackages(), $def);

        $this->assertContains('ui-build', $result['jobs']['split']['needs']);
    }

    public function test_split_needs_without_ui_build(): void
    {
        $def = new PipelineDefinition(uiBuild: false);
        $result = $this->generator->generate($this->defaultPackages(), $def);

        $this->assertNotContains('ui-build', $result['jobs']['split']['needs']);
        $this->assertContains('tests', $result['jobs']['split']['needs']);
    }

    public function test_split_has_fail_fast_false(): void
    {
        $result = $this->generate();
        $this->assertFalse($result['jobs']['split']['strategy']['fail-fast']);
    }

    public function test_explicit_permissions(): void
    {
        $result = $this->generate();
        $this->assertArrayHasKey('permissions', $result);
        $this->assertSame(['contents' => 'read'], $result['permissions']);
    }

    public function test_push_trigger_branches_and_tags(): void
    {
        $result = $this->generate();
        $this->assertSame(['main'], $result['on']['push']['branches']);
        $this->assertSame(['*'], $result['on']['push']['tags']);
    }

    /** @return array<string, mixed> */
    private function generate(): array
    {
        return $this->generator->generate($this->defaultPackages(), new PipelineDefinition());
    }

    /** @return list<SplitPackage> */
    private function defaultPackages(): array
    {
        return [
            new SplitPackage('packages/Vortos/src/Domain', 'vortos-domain'),
        ];
    }
}
