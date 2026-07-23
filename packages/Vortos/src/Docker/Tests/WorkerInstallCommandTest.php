<?php

declare(strict_types=1);

namespace Vortos\Docker\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Docker\Command\WorkerInstallCommand;
use Vortos\Docker\Worker\SupervisorFileManager;
use Vortos\Docker\Worker\WorkerProcessDefinition;
use Vortos\Docker\Worker\WorkerProcessRegistry;

/**
 * The CI gate. --dry-run exits 0 with drift pending and so could never stop a build; --check must.
 */
final class WorkerInstallCommandTest extends TestCase
{
    private string $projectDir;
    private string $originalCwd;

    protected function setUp(): void
    {
        $this->originalCwd = (string) getcwd();
        $this->projectDir = sys_get_temp_dir() . '/vortos_worker_cmd_' . uniqid('', true);
        mkdir($this->projectDir . '/docker/worker', 0755, true);
        mkdir($this->projectDir . '/docker/backup', 0755, true);
        chdir($this->projectDir);
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
        $this->removeDirectory($this->projectDir);
    }

    public function test_check_fails_when_a_registered_worker_has_no_program_anywhere(): void
    {
        $this->writeConfig('docker/worker/supervisord.conf', "[supervisord]\nnodaemon=true\n");

        $tester = $this->tester($this->registry('alerts-drain'));
        $exit = $tester->execute(['--check' => true]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('alerts-drain', $tester->getDisplay());
    }

    /**
     * Workers are split across containers deliberately — the scheduler daemon must run on exactly
     * one node. Coverage across the given configs, not completeness of each one.
     */
    public function test_check_passes_when_workers_are_split_across_configs(): void
    {
        $this->install('alerts-drain', 'docker/worker/supervisord.conf');
        $this->install('scheduler-daemon', 'docker/backup/supervisord.scheduler.conf');

        $tester = $this->tester($this->registry('alerts-drain', 'scheduler-daemon'));
        $exit = $tester->execute(['--check' => true, '--path' => [
            'docker/worker/supervisord.conf',
            'docker/backup/supervisord.scheduler.conf',
        ]]);

        self::assertSame(Command::SUCCESS, $exit);
    }

    public function test_check_still_fails_for_a_worker_in_none_of_the_configs(): void
    {
        $this->install('alerts-drain', 'docker/worker/supervisord.conf');
        $this->install('scheduler-daemon', 'docker/backup/supervisord.scheduler.conf');

        $tester = $this->tester($this->registry('alerts-drain', 'scheduler-daemon', 'outbox-relay'));
        $exit = $tester->execute(['--check' => true, '--path' => [
            'docker/worker/supervisord.conf',
            'docker/backup/supervisord.scheduler.conf',
        ]]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('outbox-relay', $tester->getDisplay());
    }

    public function test_installing_refuses_multiple_target_configs(): void
    {
        $tester = $this->tester($this->registry('alerts-drain'));
        $exit = $tester->execute(['--path' => [
            'docker/worker/supervisord.conf',
            'docker/backup/supervisord.scheduler.conf',
        ]]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('only repeatable with --check', $tester->getDisplay());
    }

    public function test_install_then_check_is_clean(): void
    {
        $registry = $this->registry('alerts-drain');

        $install = $this->tester($registry);
        self::assertSame(Command::SUCCESS, $install->execute([]));

        $check = $this->tester($registry);
        self::assertSame(Command::SUCCESS, $check->execute(['--check' => true]));
    }

    private function tester(WorkerProcessRegistry $registry): CommandTester
    {
        $command = new WorkerInstallCommand($registry, new SupervisorFileManager());
        (new Application())->add($command);

        return new CommandTester($command);
    }

    private function registry(string ...$names): WorkerProcessRegistry
    {
        $registry = new WorkerProcessRegistry();
        foreach ($names as $name) {
            $registry->add(new WorkerProcessDefinition(
                name: $name,
                command: 'php /var/www/html/bin/console ' . $name,
                description: 'Test worker ' . $name,
            ));
        }

        return $registry;
    }

    private function writeConfig(string $relative, string $contents): void
    {
        file_put_contents($this->projectDir . '/' . $relative, $contents);
    }

    /** Install a single worker's managed block into a specific config, the way each container is built. */
    private function install(string $worker, string $path): void
    {
        (new SupervisorFileManager())->install(
            $this->projectDir,
            $this->registry($worker),
            false,
            $path,
        );
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
