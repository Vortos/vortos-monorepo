<?php

declare(strict_types=1);

namespace Vortos\Tests\Migration\Service;

use PHPUnit\Framework\TestCase;
use Vortos\Foundation\Module\ModulePathResolver;
use Vortos\Migration\Service\ModuleMigrationRegistry;
use Vortos\Migration\Service\ModuleSchemaProviderScanner;

final class ModuleMigrationRegistryTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/vortos_registry_test_' . uniqid('', true);
        mkdir($this->tempDir . '/vendor/composer', 0755, true);
        mkdir($this->tempDir . '/migrations', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function test_maps_legacy_sql_manifest_entry_to_schema_provider(): void
    {
        $this->writeInstalledJson(['Authorization']);
        $this->writeProvider('Authorization', '001_authorization_rbac.php', 'Authorization', 'authorization.rbac');
        $this->writeManifest([
            'packages/Vortos/src/Authorization/Resources/migrations/001_authorization_rbac.sql' => [
                'class' => 'App\\Migrations\\Version20260505114121',
                'published_at' => '2026-05-05T11:41:21+00:00',
            ],
        ]);

        $descriptor = $this->registry()->descriptorForClass('App\\Migrations\\Version20260505114121');

        $this->assertNotNull($descriptor);
        $this->assertSame('Authorization', $descriptor->module());
        $this->assertSame('packages/Vortos/src/Authorization/Resources/migrations/001_authorization_rbac.sql', $descriptor->source());
        $this->assertSame(['sample_table'], $descriptor->ownership()->tables());
        $this->assertSame(['idx_sample_table_name'], $descriptor->ownership()->indexes());
        $this->assertNotNull($descriptor->provider());
    }

    public function test_reads_v2_manifest_objects_without_provider(): void
    {
        $this->writeInstalledJson([]);
        $this->writeManifest([
            'legacy/custom.sql' => [
                'class' => 'App\\Migrations\\Version20260101000000',
                'module' => 'Legacy',
                'filename' => 'custom.sql',
                'objects' => [
                    'tables' => ['legacy_table'],
                    'indexes' => ['idx_legacy_table_name'],
                ],
            ],
        ]);

        $descriptor = $this->registry()->descriptorForClass('App\\Migrations\\Version20260101000000');

        $this->assertNotNull($descriptor);
        $this->assertSame('Legacy', $descriptor->module());
        $this->assertSame(['legacy_table'], $descriptor->ownership()->tables());
        $this->assertSame(['idx_legacy_table_name'], $descriptor->ownership()->indexes());
        $this->assertNull($descriptor->provider());
    }

    private function registry(): ModuleMigrationRegistry
    {
        return new ModuleMigrationRegistry(
            new ModuleSchemaProviderScanner(new ModulePathResolver($this->tempDir), $this->tempDir),
            $this->tempDir,
        );
    }

    /**
     * @param string[] $modules
     */
    private function writeInstalledJson(array $modules): void
    {
        $packages = array_map(static fn(string $m) => [
            'name' => 'vortos/vortos-' . strtolower($m),
            'install-path' => '../../packages/Vortos/src/' . $m,
        ], $modules);

        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode(['packages' => $packages]),
        );
    }

    /**
     * @param array<string, mixed> $published
     */
    private function writeManifest(array $published): void
    {
        file_put_contents(
            $this->tempDir . '/migrations/.vortos-published.json',
            json_encode(['version' => 2, 'published' => $published]),
        );
    }

    private function writeProvider(string $module, string $filename, string $providerModule, string $id): void
    {
        $dir = $this->tempDir . '/packages/Vortos/src/' . $module . '/Resources/migrations';
        mkdir($dir, 0755, true);

        file_put_contents($dir . '/' . $filename, <<<PHP
<?php

use Doctrine\DBAL\Schema\Schema;
use Vortos\Migration\Schema\AbstractModuleSchemaProvider;

return new class extends AbstractModuleSchemaProvider {
    public function module(): string { return '{$providerModule}'; }
    public function id(): string { return '{$id}'; }
    public function description(): string { return 'Sample'; }
    public function define(Schema \$schema): void
    {
        \$table = \$schema->createTable('sample_table');
        \$table->addColumn('id', 'integer');
        \$table->addColumn('name', 'string', ['length' => 190]);
        \$table->setPrimaryKey(['id']);
        \$table->addIndex(['name'], 'idx_sample_table_name');
    }
};
PHP);
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
