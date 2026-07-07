<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Service;

use PHPUnit\Framework\TestCase;
use Vortos\Foundation\Module\ModulePathResolver;
use Vortos\Migration\Service\ModuleSchemaProviderScanner;
use Vortos\Migration\Service\ModuleStubScanner;
use Vortos\Migration\Service\UnpublishedStubDetector;

/**
 * R8-1: the detector is the single source of truth for "would publish emit anything?" — the deploy
 * gate and migrate:status both rely on it agreeing with the publisher.
 */
final class UnpublishedStubDetectorTest extends TestCase
{
    private string $tempDir;

    /** @var list<string> */
    private array $registeredModules = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/vortos_stub_detect_' . uniqid('', true);
        $this->registeredModules = [];
        mkdir($this->tempDir . '/migrations', 0755, true);
        mkdir($this->tempDir . '/vendor/composer', 0755, true);
        $this->writeInstalledJson();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function test_no_stubs_means_nothing_unpublished(): void
    {
        $report = $this->detector()->detect();

        $this->assertFalse($report->hasUnpublished());
        $this->assertSame(0, $report->count());
        $this->assertSame([], $report->labels());
    }

    public function test_reports_a_brand_new_sql_stub_as_unpublished(): void
    {
        $this->makeSqlStub('Messaging', '001_outbox.sql', 'CREATE TABLE outbox (id INT)');

        $report = $this->detector()->detect();

        $this->assertTrue($report->hasUnpublished());
        $this->assertSame(1, $report->count());
        $this->assertSame(['Messaging/001_outbox.sql'], $report->labels());
    }

    public function test_published_stub_is_not_reported(): void
    {
        $this->makeSqlStub('Messaging', '001_outbox.sql', 'CREATE TABLE outbox (id INT)');
        $this->writeManifest([
            'packages/Vortos/src/Messaging/Resources/migrations/001_outbox.sql' => ['class' => 'App\\Migrations\\Version1'],
        ]);

        $report = $this->detector()->detect();

        $this->assertFalse($report->hasUnpublished());
    }

    public function test_mix_of_published_and_unpublished(): void
    {
        $this->makeSqlStub('Messaging', '001_outbox.sql', 'CREATE TABLE outbox (id INT)');
        $this->makeSqlStub('Scheduler', '002_fire_queue.sql', 'ALTER TABLE fire_queue ADD COLUMN available_at TIMESTAMPTZ');
        $this->writeManifest([
            'packages/Vortos/src/Messaging/Resources/migrations/001_outbox.sql' => ['class' => 'App\\Migrations\\Version1'],
        ]);

        $report = $this->detector()->detect();

        $this->assertSame(1, $report->count());
        $this->assertSame(['Scheduler/002_fire_queue.sql'], $report->labels());
    }

    public function test_provider_published_under_legacy_sql_key_is_not_reported(): void
    {
        // A schema-provider (.php) stub whose manifest entry is the legacy .sql key (pre-.php publish)
        // must still count as published — the exact rule the publisher uses.
        $this->makeSchemaProvider('Messaging', '001_outbox.php', 'Messaging', 'messaging.outbox');
        $this->writeManifest([
            'packages/Vortos/src/Messaging/Resources/migrations/001_outbox.sql' => ['class' => 'App\\Migrations\\Version1'],
        ]);

        $report = $this->detector()->detect();

        $this->assertFalse($report->hasUnpublished(), 'legacy .sql manifest key should mark the .php provider published');
    }

    public function test_sql_counterpart_of_a_provider_is_not_double_counted(): void
    {
        // When both a .php provider and its .sql counterpart exist, only the provider is a source.
        $this->makeSchemaProvider('Messaging', '001_outbox.php', 'Messaging', 'messaging.outbox');
        $this->makeSqlStub('Messaging', '001_outbox.sql', 'CREATE TABLE outbox (id INT)');

        $report = $this->detector()->detect();

        $this->assertSame(1, $report->count(), 'the .sql counterpart must not be a separate source');
        $this->assertSame(['Messaging/001_outbox.php'], $report->labels());
    }

    public function test_corrupt_manifest_throws_so_the_gate_can_fail_closed(): void
    {
        $this->makeSqlStub('Messaging', '001_outbox.sql', 'CREATE TABLE outbox (id INT)');
        file_put_contents($this->tempDir . '/migrations/.vortos-published.json', '{not json');

        $this->expectException(\JsonException::class);
        $this->detector()->detect();
    }

    private function detector(): UnpublishedStubDetector
    {
        $scanner = new ModuleStubScanner(new ModulePathResolver($this->tempDir), $this->tempDir);
        $schemaScanner = new ModuleSchemaProviderScanner(new ModulePathResolver($this->tempDir), $this->tempDir);

        return new UnpublishedStubDetector($scanner, $this->tempDir, $schemaScanner);
    }

    /** @param array<string, mixed> $published */
    private function writeManifest(array $published): void
    {
        file_put_contents(
            $this->tempDir . '/migrations/.vortos-published.json',
            json_encode(['version' => 2, 'published' => $published]),
        );
    }

    private function makeSqlStub(string $module, string $filename, string $sql): void
    {
        $dir = $this->tempDir . '/packages/Vortos/src/' . $module . '/Resources/migrations';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($dir . '/' . $filename, $sql);
        $this->register($module);
    }

    private function makeSchemaProvider(string $module, string $filename, string $providerModule, string $id): void
    {
        $dir = $this->tempDir . '/packages/Vortos/src/' . $module . '/Resources/migrations';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($dir . '/' . $filename, <<<PHP
<?php

use Doctrine\DBAL\Schema\Schema;
use Vortos\Migration\Schema\AbstractModuleSchemaProvider;

return new class extends AbstractModuleSchemaProvider {
    public function module(): string { return '{$providerModule}'; }
    public function id(): string { return '{$id}'; }
    public function description(): string { return 'Vortos outbox'; }
    public function define(Schema \$schema): void
    {
        \$table = \$schema->createTable('vortos_outbox');
        \$table->addColumn('id', 'guid');
        \$table->setPrimaryKey(['id']);
    }
};
PHP);
        $this->register($module);
    }

    private function register(string $module): void
    {
        if (!in_array($module, $this->registeredModules, true)) {
            $this->registeredModules[] = $module;
            $this->writeInstalledJson();
        }
    }

    private function writeInstalledJson(): void
    {
        $packages = array_map(static fn (string $m): array => [
            'name'         => 'vortos/vortos-' . strtolower($m),
            'install-path' => '../../packages/Vortos/src/' . $m,
        ], $this->registeredModules);

        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode(['packages' => $packages]),
        );
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
