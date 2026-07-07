<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Preflight\Check;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Preflight\Check\UnpublishedStubCheck;
use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightStatus;
use Vortos\Deploy\Tests\Fixtures\PreflightTestFactory;
use Vortos\Foundation\Module\ModulePathResolver;
use Vortos\Migration\Service\ModuleSchemaProviderScanner;
use Vortos\Migration\Service\ModuleStubScanner;
use Vortos\Migration\Service\UnpublishedStubDetector;

final class UnpublishedStubCheckTest extends TestCase
{
    use PreflightTestFactory;

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/vortos_stub_check_' . uniqid('', true);
        mkdir($this->tempDir . '/migrations', 0755, true);
        mkdir($this->tempDir . '/vendor/composer', 0755, true);
        file_put_contents($this->tempDir . '/vendor/composer/installed.json', json_encode(['packages' => []]));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function test_id_and_category_are_stable(): void
    {
        $check = new UnpublishedStubCheck($this->detector());

        $this->assertSame('schema.unpublished-stubs', $check->id());
        $this->assertSame(PreflightCategory::Schema, $check->category());
    }

    public function test_passes_when_everything_is_published(): void
    {
        $check = new UnpublishedStubCheck($this->detector());

        $finding = $check->check($this->context());

        $this->assertSame(PreflightStatus::Pass, $finding->status);
    }

    public function test_fails_when_an_unpublished_stub_exists(): void
    {
        $this->makeInstalledModule('Scheduler');
        $this->writeStub('Scheduler', '20260706_fire_queue.sql', 'ALTER TABLE scheduler_fire_queue ADD COLUMN available_at TIMESTAMPTZ');

        $check = new UnpublishedStubCheck($this->detector());
        $finding = $check->check($this->context());

        $this->assertSame(PreflightStatus::Fail, $finding->status);
        $this->assertStringContainsString('Scheduler/20260706_fire_queue.sql', (string) $finding->detail);
        $this->assertStringContainsString('vortos:migrate:publish', (string) $finding->remediation);
    }

    public function test_detection_error_fails_closed(): void
    {
        $this->makeInstalledModule('Scheduler');
        $this->writeStub('Scheduler', '001.sql', 'CREATE TABLE t (id INT)');
        file_put_contents($this->tempDir . '/migrations/.vortos-published.json', '{corrupt');

        $check = new UnpublishedStubCheck($this->detector());
        $finding = $check->check($this->context());

        $this->assertSame(PreflightStatus::Fail, $finding->status, 'an unreadable manifest must never read as "nothing pending"');
    }

    private function detector(): UnpublishedStubDetector
    {
        $scanner = new ModuleStubScanner(new ModulePathResolver($this->tempDir), $this->tempDir);
        $schemaScanner = new ModuleSchemaProviderScanner(new ModulePathResolver($this->tempDir), $this->tempDir);

        return new UnpublishedStubDetector($scanner, $this->tempDir, $schemaScanner);
    }

    private function makeInstalledModule(string $module): void
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
