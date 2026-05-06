<?php

declare(strict_types=1);

namespace Vortos\Tests\Migration\Service;

use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Metadata\MigrationPlan;
use Doctrine\Migrations\Metadata\MigrationPlanList;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\TestCase;
use Vortos\Foundation\Module\ModulePathResolver;
use Vortos\Migration\Service\MigrationDriftDetector;
use Vortos\Migration\Service\MigrationPreflight;
use Vortos\Migration\Service\MigrationSchemaInspectorInterface;
use Vortos\Migration\Service\ModuleMigrationRegistry;
use Vortos\Migration\Service\ModuleSchemaProviderScanner;
use Vortos\Migration\Schema\ModuleMigrationDescriptor;

final class MigrationPreflightTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/vortos_preflight_test_' . uniqid('', true);
        mkdir($this->tempDir . '/vendor/composer', 0755, true);
        mkdir($this->tempDir . '/migrations', 0755, true);
        $this->writeInstalledJson();
        $this->writeProvider();
        $this->writeManifest();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function test_returns_blocking_report_for_compatible_existing_schema(): void
    {
        $preflight = new MigrationPreflight(
            $this->registry(),
            new MigrationDriftDetector($this->inspector(
                existingTables: ['sample_table'],
                missingTables: [],
                existingIndexes: ['idx_sample_table_name'],
                missingIndexes: [],
            )),
        );

        $reports = $preflight->blockingReportsForPlan($this->plan());

        $this->assertArrayHasKey('App\\Migrations\\Version20260101000000', $reports);
    }

    public function test_ignores_clean_pending_schema(): void
    {
        $preflight = new MigrationPreflight(
            $this->registry(),
            new MigrationDriftDetector($this->inspector(
                existingTables: [],
                missingTables: ['sample_table'],
                existingIndexes: [],
                missingIndexes: ['idx_sample_table_name'],
            )),
        );

        $this->assertSame([], $preflight->blockingReportsForPlan($this->plan()));
    }

    private function registry(): ModuleMigrationRegistry
    {
        return new ModuleMigrationRegistry(
            new ModuleSchemaProviderScanner(new ModulePathResolver($this->tempDir), $this->tempDir),
            $this->tempDir,
        );
    }

    private function plan(): MigrationPlanList
    {
        return new MigrationPlanList([
            new MigrationPlan(
                new Version('App\\Migrations\\Version20260101000000'),
                $this->createMock(AbstractMigration::class),
                Direction::UP,
            ),
        ], Direction::UP);
    }

    /**
     * @param string[] $existingTables
     * @param string[] $missingTables
     * @param string[] $existingIndexes
     * @param string[] $missingIndexes
     */
    private function inspector(
        array $existingTables,
        array $missingTables,
        array $existingIndexes,
        array $missingIndexes,
    ): MigrationSchemaInspectorInterface {
        return new class($existingTables, $missingTables, $existingIndexes, $missingIndexes) implements MigrationSchemaInspectorInterface {
            public function __construct(
                private readonly array $existingTables,
                private readonly array $missingTables,
                private readonly array $existingIndexes,
                private readonly array $missingIndexes,
            ) {
            }

            public function existingTables(ModuleMigrationDescriptor $descriptor): array
            {
                return $this->existingTables;
            }

            public function missingTables(ModuleMigrationDescriptor $descriptor): array
            {
                return $this->missingTables;
            }

            public function existingIndexes(ModuleMigrationDescriptor $descriptor): array
            {
                return $this->existingIndexes;
            }

            public function missingIndexes(ModuleMigrationDescriptor $descriptor): array
            {
                return $this->missingIndexes;
            }

            public function missingColumns(ModuleMigrationDescriptor $descriptor): array
            {
                return [];
            }
        };
    }

    private function writeInstalledJson(): void
    {
        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode(['packages' => [[
                'name' => 'vortos/vortos-sample',
                'install-path' => '../../packages/Vortos/src/Sample',
            ]]]),
        );
    }

    private function writeManifest(): void
    {
        file_put_contents(
            $this->tempDir . '/migrations/.vortos-published.json',
            json_encode(['version' => 2, 'published' => [
                'packages/Vortos/src/Sample/Resources/migrations/001_sample.php' => [
                    'class' => 'App\\Migrations\\Version20260101000000',
                    'source_type' => 'schema',
                ],
            ]]),
        );
    }

    private function writeProvider(): void
    {
        $dir = $this->tempDir . '/packages/Vortos/src/Sample/Resources/migrations';
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/001_sample.php', <<<'PHP'
<?php

use Doctrine\DBAL\Schema\Schema;
use Vortos\Migration\Schema\AbstractModuleSchemaProvider;

return new class extends AbstractModuleSchemaProvider {
    public function module(): string { return 'Sample'; }
    public function id(): string { return 'sample'; }
    public function description(): string { return 'Sample'; }
    public function define(Schema $schema): void
    {
        $table = $schema->createTable('sample_table');
        $table->addColumn('id', 'integer');
        $table->addColumn('name', 'string');
        $table->setPrimaryKey(['id']);
        $table->addIndex(['name'], 'idx_sample_table_name');
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
