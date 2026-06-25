<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Driver\GitHubActions\SplitWorkflowGenerator;
use Vortos\Pipeline\Driver\GitHubActions\WorkflowYamlWriter;
use Vortos\Pipeline\Model\SplitPackage;

final class SplitWorkflowParityTest extends TestCase
{
    public function test_regenerated_split_covers_all_current_packages(): void
    {
        $currentSplitPath = dirname(__DIR__, 6) . '/.github/workflows/split.yml';
        if (!is_file($currentSplitPath)) {
            $this->markTestSkipped('No .github/workflows/split.yml found — nothing to compare.');
        }

        $currentContents = (string) file_get_contents($currentSplitPath);

        $currentRepos = $this->extractSplitRepositories($currentContents);
        $this->assertNotEmpty($currentRepos, 'Could not extract split repositories from current split.yml');

        $splitPackages = $this->extractSplitPackages($currentContents);
        $this->assertNotEmpty($splitPackages, 'Could not extract split packages from current split.yml');

        $definition = new PipelineDefinition(
            benchmark: true,
            nodeVersion: '20',
        );

        $generator = new SplitWorkflowGenerator();
        $generatedArray = $generator->generate($splitPackages, $definition);

        $generatedRepos = [];
        foreach ($generatedArray['jobs']['split']['strategy']['matrix']['package'] as $pkg) {
            $generatedRepos[] = $pkg['split_repository'];
        }

        foreach ($currentRepos as $repo) {
            $this->assertContains(
                $repo,
                $generatedRepos,
                'Split repository "' . $repo . '" from current split.yml is missing in generated output',
            );
        }
    }

    public function test_generated_split_has_tests_job(): void
    {
        $definition = new PipelineDefinition();
        $generator = new SplitWorkflowGenerator();

        $packages = [new SplitPackage('packages/Vortos/src/Domain', 'vortos-domain')];
        $result = $generator->generate($packages, $definition);

        $this->assertArrayHasKey('tests', $result['jobs']);
    }

    public function test_generated_split_has_split_job(): void
    {
        $definition = new PipelineDefinition();
        $generator = new SplitWorkflowGenerator();

        $packages = [new SplitPackage('packages/Vortos/src/Domain', 'vortos-domain')];
        $result = $generator->generate($packages, $definition);

        $this->assertArrayHasKey('split', $result['jobs']);
    }

    public function test_split_job_needs_tests(): void
    {
        $definition = new PipelineDefinition();
        $generator = new SplitWorkflowGenerator();

        $packages = [new SplitPackage('packages/Vortos/src/Domain', 'vortos-domain')];
        $result = $generator->generate($packages, $definition);

        $this->assertContains('tests', $result['jobs']['split']['needs']);
    }

    public function test_benchmark_job_included_when_enabled(): void
    {
        $definition = new PipelineDefinition(benchmark: true);
        $generator = new SplitWorkflowGenerator();

        $packages = [new SplitPackage('packages/Vortos/src/Domain', 'vortos-domain')];
        $result = $generator->generate($packages, $definition);

        $this->assertArrayHasKey('benchmark', $result['jobs']);
        $this->assertContains('tests', $result['jobs']['benchmark']['needs']);
    }

    public function test_benchmark_job_omitted_when_disabled(): void
    {
        $definition = new PipelineDefinition(benchmark: false);
        $generator = new SplitWorkflowGenerator();

        $packages = [new SplitPackage('packages/Vortos/src/Domain', 'vortos-domain')];
        $result = $generator->generate($packages, $definition);

        $this->assertArrayNotHasKey('benchmark', $result['jobs']);
    }

    public function test_ui_build_job_included_when_enabled(): void
    {
        $definition = new PipelineDefinition(
            uiBuild: true,
            nodeVersion: '20',
            uiBuildPath: 'packages/feature-flags-admin',
        );
        $generator = new SplitWorkflowGenerator();

        $packages = [new SplitPackage('packages/Vortos/src/Domain', 'vortos-domain')];
        $result = $generator->generate($packages, $definition);

        $this->assertArrayHasKey('ui-build', $result['jobs']);
        $this->assertContains('ui-build', $result['jobs']['split']['needs']);
    }

    public function test_ui_build_job_omitted_when_disabled(): void
    {
        $definition = new PipelineDefinition(uiBuild: false);
        $generator = new SplitWorkflowGenerator();

        $packages = [new SplitPackage('packages/Vortos/src/Domain', 'vortos-domain')];
        $result = $generator->generate($packages, $definition);

        $this->assertArrayNotHasKey('ui-build', $result['jobs']);
    }

    /** @return list<string> */
    private function extractSplitRepositories(string $yaml): array
    {
        $repos = [];
        preg_match_all('/split_repository:\s+[\'"]?([a-z0-9-]+)[\'"]?/', $yaml, $matches);
        foreach ($matches[1] as $repo) {
            $repos[] = $repo;
        }

        return $repos;
    }

    /** @return list<SplitPackage> */
    private function extractSplitPackages(string $yaml): array
    {
        $packages = [];
        preg_match_all(
            '/local_path:\s+[\'"]?([^\s\'"]+)[\'"]?\s+split_repository:\s+[\'"]?([a-z0-9-]+)[\'"]?/',
            $yaml,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $match) {
            $packages[] = new SplitPackage($match[1], $match[2]);
        }

        return $packages;
    }
}
