<?php

declare(strict_types=1);

namespace Vortos\Tests\Migration\Service;

use PHPUnit\Framework\TestCase;
use Vortos\Foundation\Module\ModulePathResolver;
use Vortos\Migration\Schema\ModuleSchemaProviderInterface;
use Vortos\Migration\Service\ModuleSchemaProviderScanner;

final class ModuleSchemaProviderScannerTest extends TestCase
{
    private string $tempDir;

    /** @var list<string> */
    private array $registeredModules = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/vortos_schema_provider_test_' . uniqid('', true);
        $this->registeredModules = [];

        mkdir($this->tempDir . '/vendor/composer', 0755, true);
        $this->writeInstalledJson();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function test_returns_empty_when_no_schema_providers_exist(): void
    {
        $this->assertSame([], $this->makeScanner()->scan());
    }

    public function test_discovers_php_schema_provider_in_module_resources_directory(): void
    {
        $this->createProviderStub('Messaging', '001_outbox.php', 'Messaging', 'messaging.outbox', 'Vortos outbox');

        $providers = $this->makeScanner()->scan();

        $this->assertCount(1, $providers);
        $this->assertSame('Messaging', $providers[0]['module']);
        $this->assertSame('001_outbox.php', $providers[0]['filename']);
        $this->assertInstanceOf(ModuleSchemaProviderInterface::class, $providers[0]['provider']);
        $this->assertSame('messaging.outbox', $providers[0]['provider']->id());
    }

    public function test_provider_ownership_is_derived_from_dbal_schema(): void
    {
        $this->createProviderStub('Authorization', '001_rbac.php', 'Authorization', 'authorization.rbac', 'Authorization rbac');

        $providers = $this->makeScanner()->scan();
        $ownership = $providers[0]['provider']->ownership();

        $this->assertSame(['sample_table'], $ownership->tables());
        $this->assertSame(['idx_sample_table_name'], $ownership->indexes());
    }

    public function test_invalid_provider_file_throws_clear_exception(): void
    {
        $dir = $this->moduleStubDir('Messaging');
        file_put_contents($dir . '/001_invalid.php', "<?php\nreturn new stdClass();\n");
        $this->registeredModules[] = 'Messaging';
        $this->writeInstalledJson();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(ModuleSchemaProviderInterface::class);

        $this->makeScanner()->scan();
    }

    public function test_additional_scan_path_is_included(): void
    {
        $extraDir = $this->tempDir . '/extra/migrations';
        mkdir($extraDir, 0755, true);
        file_put_contents($extraDir . '/001_custom.php', $this->providerPhp('Custom', 'custom.table', 'Custom table'));

        $scanner = $this->makeScanner();
        $scanner->addScanPath('extra/migrations/*.php');

        $providers = $scanner->scan();

        $this->assertCount(1, $providers);
        $this->assertSame('Custom', $providers[0]['provider']->module());
    }

    private function makeScanner(): ModuleSchemaProviderScanner
    {
        return new ModuleSchemaProviderScanner(
            new ModulePathResolver($this->tempDir),
            $this->tempDir,
        );
    }

    private function createProviderStub(
        string $module,
        string $filename,
        string $providerModule,
        string $id,
        string $description,
    ): void {
        $dir = $this->moduleStubDir($module);
        file_put_contents($dir . '/' . $filename, $this->providerPhp($providerModule, $id, $description));

        if (!in_array($module, $this->registeredModules, true)) {
            $this->registeredModules[] = $module;
            $this->writeInstalledJson();
        }
    }

    private function providerPhp(string $module, string $id, string $description): string
    {
        return <<<PHP
<?php

use Doctrine\DBAL\Schema\Schema;
use Vortos\Migration\Schema\AbstractModuleSchemaProvider;

return new class extends AbstractModuleSchemaProvider {
    public function module(): string { return '{$module}'; }
    public function id(): string { return '{$id}'; }
    public function description(): string { return '{$description}'; }
    public function define(Schema \$schema): void
    {
        \$table = \$schema->createTable('sample_table');
        \$table->addColumn('id', 'integer');
        \$table->addColumn('name', 'string', ['length' => 190]);
        \$table->setPrimaryKey(['id']);
        \$table->addIndex(['name'], 'idx_sample_table_name');
    }
};
PHP;
    }

    private function moduleStubDir(string $module): string
    {
        $dir = $this->tempDir . '/packages/Vortos/src/' . $module . '/Resources/migrations';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir;
    }

    private function writeInstalledJson(): void
    {
        $packages = array_map(static fn(string $m) => [
            'name' => 'vortos/vortos-' . strtolower($m),
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
