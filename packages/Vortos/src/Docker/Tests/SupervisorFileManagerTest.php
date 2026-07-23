<?php

declare(strict_types=1);

namespace Vortos\Docker\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Docker\Worker\SupervisorChange;
use Vortos\Docker\Worker\SupervisorFileManager;
use Vortos\Docker\Worker\WorkerProcessDefinition;
use Vortos\Docker\Worker\WorkerProcessRegistry;

final class SupervisorFileManagerTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/vortos_supervisor_' . uniqid('', true);
        mkdir($this->projectDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    public function test_installs_managed_block_without_touching_unmanaged_content(): void
    {
        $path = $this->projectDir . '/docker/worker/supervisord.conf';
        mkdir(dirname($path), 0755, true);
        file_put_contents($path, "[supervisord]\nnodaemon=true\n\n[program:custom]\ncommand=php custom.php\n");

        $manager = new SupervisorFileManager();
        $result = $manager->install($this->projectDir, new WorkerProcessRegistry([
            new WorkerProcessDefinition('aws-ses-outbox-relay', 'php bin/console vortos:ses:outbox:relay', 'Relay SES.'),
        ]));

        $contents = (string) file_get_contents($path);

        $this->assertTrue($result->written);
        $this->assertStringContainsString('[program:custom]', $contents);
        $this->assertStringContainsString('; <vortos-worker name="aws-ses-outbox-relay">', $contents);
        $this->assertStringContainsString('command=php bin/console vortos:ses:outbox:relay', $contents);
    }

    /**
     * The gate behind `vortos:worker:install --check`. A worker registered in code but absent from
     * the committed config never starts, and until this existed nothing failed: `--dry-run` reports
     * the drift and still exits 0, so it could never stop a build.
     */
    public function test_drift_reports_a_registered_worker_with_no_block(): void
    {
        $path = $this->projectDir . '/docker/worker/supervisord.conf';
        mkdir(dirname($path), 0755, true);
        file_put_contents($path, "[supervisord]\nnodaemon=true\n");

        $drift = (new SupervisorFileManager())->drift($this->projectDir, new WorkerProcessRegistry([
            new WorkerProcessDefinition('alerts-drain', 'php bin/console vortos:alerts:drain --loop', 'Drain alerts.'),
        ]));

        $this->assertSame(['alerts-drain'], $drift['missing']);
        $this->assertSame([], $drift['stale']);
    }

    public function test_drift_reports_a_block_whose_body_diverged_as_stale(): void
    {
        $manager = new SupervisorFileManager();
        $manager->install($this->projectDir, new WorkerProcessRegistry([
            new WorkerProcessDefinition('alerts-drain', 'php bin/console vortos:alerts:drain --loop', 'Drain alerts.'),
        ]));

        // Same worker, command changed in code but never reinstalled.
        $drift = $manager->drift($this->projectDir, new WorkerProcessRegistry([
            new WorkerProcessDefinition('alerts-drain', 'php bin/console vortos:alerts:drain --loop --interval=30', 'Drain alerts.'),
        ]));

        $this->assertSame([], $drift['missing']);
        $this->assertSame(['alerts-drain'], $drift['stale']);
    }

    public function test_drift_is_clean_immediately_after_install(): void
    {
        $manager = new SupervisorFileManager();
        $registry = new WorkerProcessRegistry([
            new WorkerProcessDefinition('alerts-drain', 'php bin/console vortos:alerts:drain --loop', 'Drain alerts.'),
            new WorkerProcessDefinition('messaging-outbox-relay', 'php bin/console vortos:outbox:relay', 'Relay events.'),
        ]);

        $manager->install($this->projectDir, $registry);

        $this->assertSame(
            ['missing' => [], 'stale' => []],
            $manager->drift($this->projectDir, $registry),
        );
    }

    public function test_install_is_idempotent(): void
    {
        $manager = new SupervisorFileManager();
        $registry = new WorkerProcessRegistry([
            new WorkerProcessDefinition('object-store-outbox-relay', 'php bin/console vortos:object-store:relay', 'Relay objects.'),
        ]);

        $manager->install($this->projectDir, $registry);
        $second = $manager->install($this->projectDir, $registry);

        $this->assertFalse($second->written);
        $this->assertSame(SupervisorChange::None, $second->plan->change);
    }

    public function test_dry_run_does_not_write(): void
    {
        $manager = new SupervisorFileManager();
        $result = $manager->install($this->projectDir, new WorkerProcessRegistry([
            new WorkerProcessDefinition('worker-a', 'cmd', 'Worker A.'),
        ]), dryRun: true);

        $this->assertFalse($result->written);
        $this->assertFileDoesNotExist($this->projectDir . '/docker/worker/supervisord.conf');
        $this->assertStringContainsString('[program:worker-a]', $result->plan->desired);
    }

    public function test_remove_only_deletes_managed_block(): void
    {
        $manager = new SupervisorFileManager();
        $manager->install($this->projectDir, new WorkerProcessRegistry([
            new WorkerProcessDefinition('worker-a', 'cmd-a', 'Worker A.'),
            new WorkerProcessDefinition('worker-b', 'cmd-b', 'Worker B.'),
        ]));

        $manager->remove($this->projectDir, ['worker-a']);
        $contents = (string) file_get_contents($this->projectDir . '/docker/worker/supervisord.conf');

        $this->assertStringNotContainsString('[program:worker-a]', $contents);
        $this->assertStringContainsString('[program:worker-b]', $contents);
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
