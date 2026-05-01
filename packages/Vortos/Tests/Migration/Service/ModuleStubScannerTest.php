<?php

declare(strict_types=1);

namespace Vortos\Tests\Migration\Service;

use PHPUnit\Framework\TestCase;
use Vortos\Foundation\Module\ModulePathResolver;
use Vortos\Migration\Service\ModuleStubScanner;

final class ModuleStubScannerTest extends TestCase
{
    private string $tempDir;

    /** @var list<string> */
    private array $registeredModules = [];

    protected function setUp(): void
    {
        $this->tempDir          = sys_get_temp_dir() . '/vortos_migration_test_' . uniqid('', true);
        $this->registeredModules = [];

        mkdir($this->tempDir . '/vendor/composer', 0755, true);
        $this->writeInstalledJson();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function test_returns_empty_when_no_stubs_exist(): void
    {
        $this->assertSame([], $this->makeScanner()->scan());
    }

    public function test_discovers_sql_stubs_in_module_resources_directory(): void
    {
        $this->createSqlStub('Messaging', '001_vortos_outbox.sql', 'CREATE TABLE outbox (id UUID PRIMARY KEY)');
        $this->createSqlStub('Messaging', '002_vortos_failed_messages.sql', 'CREATE TABLE failed (id UUID PRIMARY KEY)');

        $stubs = $this->makeScanner()->scan();

        $this->assertCount(2, $stubs);
        $this->assertSame('Messaging', $stubs[0]['module']);
        $this->assertSame('001_vortos_outbox.sql', $stubs[0]['filename']);
        $this->assertSame('Messaging', $stubs[1]['module']);
        $this->assertSame('002_vortos_failed_messages.sql', $stubs[1]['filename']);
    }

    public function test_discovers_stubs_across_multiple_modules(): void
    {
        $this->createSqlStub('Messaging', '001_outbox.sql', 'SELECT 1');
        $this->createSqlStub('Notification', '001_notifications.sql', 'SELECT 1');
        $this->createSqlStub('Billing', '001_invoices.sql', 'SELECT 1');

        $stubs = $this->makeScanner()->scan();

        $this->assertCount(3, $stubs);

        $modules = array_column($stubs, 'module');
        $this->assertContains('Messaging', $modules);
        $this->assertContains('Notification', $modules);
        $this->assertContains('Billing', $modules);
    }

    public function test_ignores_non_sql_files(): void
    {
        $stubDir = $this->moduleStubDir('Messaging');
        file_put_contents($stubDir . '/001_outbox.md', '# readme');
        file_put_contents($stubDir . '/001_outbox.txt', 'plain text');
        $this->createSqlStub('Messaging', '001_outbox.sql', 'CREATE TABLE x (id INT)');

        $stubs = $this->makeScanner()->scan();

        $this->assertCount(1, $stubs);
        $this->assertSame('001_outbox.sql', $stubs[0]['filename']);
    }

    public function test_results_are_sorted_by_filename(): void
    {
        $this->createSqlStub('Messaging', '002_second.sql', 'SELECT 1');
        $this->createSqlStub('Messaging', '001_first.sql', 'SELECT 1');
        $this->createSqlStub('Auth', '001_auth.sql', 'SELECT 1');

        $stubs = $this->makeScanner()->scan();

        $filenames = array_column($stubs, 'filename');
        $sorted    = $filenames;
        sort($sorted);

        $this->assertSame($sorted, $filenames);
    }

    public function test_relative_path_is_relative_to_project_root(): void
    {
        $this->createSqlStub('Messaging', '001_outbox.sql', 'SELECT 1');

        $stubs = $this->makeScanner()->scan();

        $this->assertStringNotContainsString($this->tempDir, $stubs[0]['relative']);
        $this->assertStringStartsWith('packages/', $stubs[0]['relative']);
    }

    public function test_absolute_path_points_to_actual_file(): void
    {
        $this->createSqlStub('Messaging', '001_outbox.sql', 'CREATE TABLE x (id INT)');

        $stubs = $this->makeScanner()->scan();

        $this->assertFileExists($stubs[0]['path']);
        $this->assertStringContainsString('CREATE TABLE', (string) file_get_contents($stubs[0]['path']));
    }

    public function test_additional_scan_path_is_included(): void
    {
        $extraDir = $this->tempDir . '/extra/migrations';
        mkdir($extraDir, 0755, true);
        file_put_contents($extraDir . '/001_custom.sql', 'CREATE TABLE custom (id INT)');

        $scanner = $this->makeScanner();
        $scanner->addScanPath('extra/migrations/*.sql');

        $stubs = $scanner->scan();

        $filenames = array_column($stubs, 'filename');
        $this->assertContains('001_custom.sql', $filenames);
    }

    public function test_module_name_extracted_correctly(): void
    {
        $this->createSqlStub('Notification', '001_push.sql', 'SELECT 1');

        $stubs = $this->makeScanner()->scan();

        $this->assertSame('Notification', $stubs[0]['module']);
    }

    private function makeScanner(): ModuleStubScanner
    {
        return new ModuleStubScanner(
            new ModulePathResolver($this->tempDir),
            $this->tempDir,
        );
    }

    private function createSqlStub(string $module, string $filename, string $sql): void
    {
        $dir = $this->moduleStubDir($module);
        file_put_contents($dir . '/' . $filename, $sql);

        if (!in_array($module, $this->registeredModules, true)) {
            $this->registeredModules[] = $module;
            $this->writeInstalledJson();
        }
    }

    private function writeInstalledJson(): void
    {
        $packages = array_map(static fn(string $m) => [
            'name'         => 'vortos/vortos-' . strtolower($m),
            'install-path' => '../../packages/Vortos/src/' . $m,
        ], $this->registeredModules);

        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode(['packages' => $packages]),
        );
    }

    private function moduleStubDir(string $module): string
    {
        $dir = $this->tempDir . '/packages/Vortos/src/' . $module . '/Resources/migrations';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
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
