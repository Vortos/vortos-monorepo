<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Runtime\MigrationAutoPublisher;
use Vortos\Foundation\Module\ModulePathResolver;
use Vortos\Migration\Command\MigratePublishCommand;
use Vortos\Migration\Generator\MigrationClassGenerator;
use Vortos\Migration\Service\ModuleSchemaProviderScanner;
use Vortos\Migration\Service\ModuleStubScanner;
use Vortos\Migration\Service\UnpublishedStubDetector;

final class MigrationAutoPublisherTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/vortos_autopub_' . uniqid('', true);
        mkdir($this->tempDir . '/migrations', 0755, true);
        mkdir($this->tempDir . '/vendor/composer', 0755, true);
        file_put_contents($this->tempDir . '/vendor/composer/installed.json', json_encode(['packages' => []]));
    }

    protected function tearDown(): void
    {
        @chmod($this->tempDir . '/migrations', 0755);
        $this->removeDirectory($this->tempDir);
    }

    public function test_returns_zero_when_nothing_pending(): void
    {
        $this->assertSame(0, $this->publisher()->publish());
    }

    public function test_publishes_pending_stub_and_returns_count(): void
    {
        $this->installModule('Messaging');
        $this->writeStub('Messaging', '001_outbox.sql', 'CREATE TABLE outbox (id INT)');

        $count = $this->publisher()->publish();

        $this->assertSame(1, $count);
        $this->assertNotEmpty(glob($this->tempDir . '/migrations/Version*.php') ?: []);
        // After publishing, a fresh detection sees nothing pending.
        $this->assertFalse($this->detector()->detect()->hasUnpublished());
    }

    public function test_throws_when_publish_cannot_write(): void
    {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('root bypasses filesystem permissions');
        }

        $this->installModule('Messaging');
        $this->writeStub('Messaging', '001_outbox.sql', 'CREATE TABLE outbox (id INT)');
        chmod($this->tempDir . '/migrations', 0500);

        $this->expectException(\RuntimeException::class);
        $this->publisher()->publish();
    }

    private function publisher(): MigrationAutoPublisher
    {
        $scanner = new ModuleStubScanner(new ModulePathResolver($this->tempDir), $this->tempDir);
        $schemaScanner = new ModuleSchemaProviderScanner(new ModulePathResolver($this->tempDir), $this->tempDir);
        $command = new MigratePublishCommand($scanner, new MigrationClassGenerator(), $this->tempDir, $schemaScanner);

        return new MigrationAutoPublisher($command, $this->detector());
    }

    private function detector(): UnpublishedStubDetector
    {
        $scanner = new ModuleStubScanner(new ModulePathResolver($this->tempDir), $this->tempDir);
        $schemaScanner = new ModuleSchemaProviderScanner(new ModulePathResolver($this->tempDir), $this->tempDir);

        return new UnpublishedStubDetector($scanner, $this->tempDir, $schemaScanner);
    }

    private function installModule(string $module): void
    {
        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode(['packages' => [[
                'name'         => 'vortos/vortos-' . strtolower($module),
                'install-path' => '../../packages/Vortos/src/' . $module,
            ]]]),
        );
    }

    private function writeStub(string $module, string $filename, string $sql): void
    {
        $dir = $this->tempDir . '/packages/Vortos/src/' . $module . '/Resources/migrations';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($dir . '/' . $filename, $sql);
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
