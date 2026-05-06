<?php

declare(strict_types=1);

namespace Vortos\Tests\Setup;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Docker\Service\DockerFilePublisher;
use Vortos\Setup\Command\SetupCommand;
use Vortos\Setup\Service\EnvironmentFileWriter;
use Vortos\Setup\Service\SetupEnvironmentChecker;
use Vortos\Setup\Service\SetupStateStore;

final class SetupCommandTest extends TestCase
{
    private string $projectDir;
    private string $stubRoot;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/vortos_setup_project_' . uniqid('', true);
        $this->stubRoot = sys_get_temp_dir() . '/vortos_setup_stubs_' . uniqid('', true);

        mkdir($this->projectDir, 0777, true);
        mkdir($this->stubRoot . '/frankenphp/docker/php', 0777, true);
        file_put_contents($this->stubRoot . '/frankenphp/docker-compose.yaml', 'services: []' . PHP_EOL);
        file_put_contents($this->stubRoot . '/frankenphp/docker/php/Dockerfile', 'FROM php' . PHP_EOL);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
        $this->removeDirectory($this->stubRoot);
    }

    public function test_dry_run_does_not_write_files(): void
    {
        $tester = $this->tester();

        $tester->execute([
            '--preset' => 'local',
            '--dry-run' => true,
            '--no-interaction' => true,
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertFileDoesNotExist($this->projectDir . '/.env.local');
        $this->assertFileDoesNotExist($this->projectDir . '/.vortos-setup.json');
    }

    public function test_setup_is_idempotent_and_preserves_generated_secrets(): void
    {
        $tester = $this->tester();

        $tester->execute([
            '--preset' => 'local',
            '--no-interaction' => true,
        ]);

        $firstEnv = (string) file_get_contents($this->projectDir . '/.env.local');
        $this->assertStringContainsString('VORTOS_CACHE_DRIVER=in-memory', $firstEnv);
        $this->assertFileExists($this->projectDir . '/.vortos-setup.json');

        $tester = $this->tester();
        $tester->execute([
            '--preset' => 'local',
            '--no-interaction' => true,
        ]);

        $secondEnv = (string) file_get_contents($this->projectDir . '/.env.local');
        $this->assertSame($this->envValue($firstEnv, 'JWT_SECRET'), $this->envValue($secondEnv, 'JWT_SECRET'));
        $this->assertSame($this->envValue($firstEnv, 'HEALTH_TOKEN'), $this->envValue($secondEnv, 'HEALTH_TOKEN'));
    }

    public function test_docker_preset_publishes_docker_files_with_backup_on_rerun(): void
    {
        file_put_contents($this->projectDir . '/docker-compose.yaml', 'custom' . PHP_EOL);

        $tester = $this->tester();
        $tester->execute([
            '--preset' => 'docker-frankenphp',
            '--no-interaction' => true,
        ]);

        $this->assertStringContainsString('services: []', (string) file_get_contents($this->projectDir . '/docker-compose.yaml'));
        $this->assertNotEmpty(glob($this->projectDir . '/docker-compose.yaml.bak.*') ?: []);
        $this->assertFileExists($this->projectDir . '/docker/php/Dockerfile');
    }

    private function tester(): CommandTester
    {
        $command = new SetupCommand(
            $this->projectDir,
            new SetupStateStore($this->projectDir),
            new EnvironmentFileWriter($this->projectDir),
            new SetupEnvironmentChecker($this->projectDir),
            new DockerFilePublisher($this->stubRoot),
        );

        $application = new Application();
        $application->add($command);

        return new CommandTester($application->find('vortos:setup'));
    }

    private function envValue(string $env, string $key): string
    {
        preg_match('/^' . preg_quote($key, '/') . '=(.+)$/m', $env, $match);

        return $match[1] ?? '';
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
