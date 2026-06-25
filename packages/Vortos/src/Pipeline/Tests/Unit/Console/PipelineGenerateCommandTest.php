<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Pipeline\Builder\PipelineBuilder;
use Vortos\Pipeline\Builder\StageGate;
use Vortos\Pipeline\Console\PipelineGenerateCommand;
use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Driver\GitHubActions\GitHubActionsEmitter;
use Vortos\Pipeline\Driver\GitHubActions\GitHubWorkflowMapper;
use Vortos\Pipeline\Driver\GitHubActions\SplitWorkflowGenerator;
use Vortos\Pipeline\Driver\GitHubActions\WorkflowYamlWriter;
use Vortos\Pipeline\Emitter\PipelineEmitterRegistry;

final class PipelineGenerateCommandTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/vortos-pipeline-test-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    public function test_dry_run_does_not_write_files(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute(['--dry-run' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('DRY-RUN', $tester->getDisplay());
        $this->assertFalse(is_dir($this->tmpDir . '/.github'));
    }

    public function test_writes_files_without_dry_run(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertTrue(is_file($this->tmpDir . '/.github/workflows/ci.yml'));
    }

    public function test_skips_existing_files_without_force(): void
    {
        mkdir($this->tmpDir . '/.github/workflows', 0755, true);
        file_put_contents($this->tmpDir . '/.github/workflows/ci.yml', 'original content');

        $tester = $this->createCommandTester();
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame('original content', file_get_contents($this->tmpDir . '/.github/workflows/ci.yml'));
        $this->assertStringContainsString('SKIPPED', $tester->getDisplay());
    }

    public function test_force_overwrites_existing_files(): void
    {
        mkdir($this->tmpDir . '/.github/workflows', 0755, true);
        file_put_contents($this->tmpDir . '/.github/workflows/ci.yml', 'original content');

        $tester = $this->createCommandTester();
        $tester->execute(['--force' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertNotSame('original content', file_get_contents($this->tmpDir . '/.github/workflows/ci.yml'));
        $this->assertStringContainsString('WRITTEN', $tester->getDisplay());
    }

    public function test_unchanged_file_reported_as_unchanged(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute([]);

        $tester2 = $this->createCommandTester();
        $tester2->execute([]);

        $this->assertSame(0, $tester2->getStatusCode());
        $this->assertStringContainsString('UNCHANGED', $tester2->getDisplay());
    }

    public function test_json_output(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute(['--json' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        $decoded = json_decode($tester->getDisplay(), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('artifacts', $decoded);
        $this->assertArrayHasKey('gated_stages', $decoded);
    }

    public function test_reports_gated_stages(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute([]);

        $this->assertStringContainsString('Gated stages', $tester->getDisplay());
    }

    private function createCommandTester(): CommandTester
    {
        $definition = new PipelineDefinition();
        $emitter = new GitHubActionsEmitter(
            new GitHubWorkflowMapper(),
            new SplitWorkflowGenerator(),
            new WorkflowYamlWriter(),
            $definition,
        );

        $registry = new PipelineEmitterRegistry(new ServiceLocator([
            'github' => fn () => $emitter,
        ]));

        $gate = new StageGate();
        $builder = new PipelineBuilder($gate);

        $command = new PipelineGenerateCommand(
            $registry,
            $builder,
            $gate,
            [],
            $this->tmpDir,
        );

        $app = new Application();
        $app->add($command);

        return new CommandTester($app->find('pipeline:generate'));
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
