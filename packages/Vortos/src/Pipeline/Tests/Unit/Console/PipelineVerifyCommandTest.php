<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Pipeline\Builder\PipelineBuilder;
use Vortos\Pipeline\Builder\StageGate;
use Vortos\Pipeline\Console\PipelineVerifyCommand;
use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Driver\GitHubActions\GitHubActionsEmitter;
use Vortos\Pipeline\Driver\GitHubActions\GitHubWorkflowMapper;
use Vortos\Pipeline\Driver\GitHubActions\SplitWorkflowGenerator;
use Vortos\Pipeline\Driver\GitHubActions\WorkflowYamlWriter;
use Vortos\Pipeline\Emitter\PipelineEmitterRegistry;

final class PipelineVerifyCommandTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/vortos-pipeline-verify-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    public function test_in_sync_returns_success(): void
    {
        $this->generateFiles();

        $tester = $this->createCommandTester();
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('in sync', $tester->getDisplay());
    }

    public function test_drifted_content_returns_failure(): void
    {
        $this->generateFiles();

        file_put_contents($this->tmpDir . '/.github/workflows/ci.yml', 'modified content');

        $tester = $this->createCommandTester();
        $tester->execute([]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('drift', $tester->getDisplay());
    }

    public function test_missing_file_returns_failure(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute([]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('missing', $tester->getDisplay());
    }

    public function test_json_output(): void
    {
        $this->generateFiles();

        $tester = $this->createCommandTester();
        $tester->execute(['--json' => true]);

        $decoded = json_decode($tester->getDisplay(), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('status', $decoded);
        $this->assertSame('in_sync', $decoded['status']);
        $this->assertArrayHasKey('drifted', $decoded);
        $this->assertArrayHasKey('in_sync', $decoded);
    }

    public function test_json_output_drifted(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute(['--json' => true]);

        $decoded = json_decode($tester->getDisplay(), true);
        $this->assertSame('drifted', $decoded['status']);
        $this->assertNotEmpty($decoded['drifted']);
    }

    private function generateFiles(): void
    {
        $definition = new PipelineDefinition();
        $emitter = new GitHubActionsEmitter(
            new GitHubWorkflowMapper(),
            new SplitWorkflowGenerator(),
            new WorkflowYamlWriter(),
            $definition,
        );

        $builder = new PipelineBuilder(new StageGate());
        $pipeline = $builder->build($definition);
        $artifacts = $emitter->emit($pipeline);

        foreach ($artifacts as $artifact) {
            $path = $this->tmpDir . '/' . $artifact->relativePath;
            $dir = \dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($path, $artifact->contents);
        }
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

        $builder = new PipelineBuilder(new StageGate());

        $command = new PipelineVerifyCommand(
            $registry,
            $builder,
            [],
            $this->tmpDir,
        );

        $app = new Application();
        $app->add($command);

        return new CommandTester($app->find('pipeline:verify'));
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
