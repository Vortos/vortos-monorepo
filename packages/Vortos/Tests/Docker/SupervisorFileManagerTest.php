<?php

declare(strict_types=1);

namespace Vortos\Tests\Docker;

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
