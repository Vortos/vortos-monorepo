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
use Vortos\Migration\Schema\MigrationDriftReport;
use Vortos\Migration\Schema\MigrationPlanItemAnalysis;
use Vortos\Migration\Schema\ModuleMigrationDescriptor;
use Vortos\Migration\Service\MigrationDriftDetector;
use Vortos\Migration\Service\MigrationPlanAnalyzer;
use Vortos\Migration\Service\MigrationRawInspectorInterface;
use Vortos\Migration\Service\MigrationSchemaInspectorInterface;
use Vortos\Migration\Service\MigrationSqlExtractorInterface;
use Vortos\Migration\Service\MigrationSqlParser;
use Vortos\Migration\Service\ModuleMigrationRegistry;
use Vortos\Migration\Service\ModuleSchemaProviderScanner;

final class MigrationPlanAnalyzerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/vortos_analyzer_test_' . uniqid('', true);
        mkdir($this->tempDir . '/vendor/composer', 0755, true);
        mkdir($this->tempDir . '/migrations', 0755, true);

        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode(['packages' => []]),
        );

        file_put_contents(
            $this->tempDir . '/migrations/.vortos-published.json',
            json_encode(['version' => 2, 'published' => []]),
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function test_safe_when_all_create_table_use_if_not_exists(): void
    {
        $analysis = $this->analyze(
            sql: "CREATE TABLE IF NOT EXISTS foo (id INT);\nCREATE INDEX IF NOT EXISTS idx_foo ON foo (id);",
            existingTables: [],
        );

        $this->assertSame(MigrationPlanItemAnalysis::Safe, $analysis->status());
        $this->assertFalse($analysis->isBlocker());
        $this->assertFalse($analysis->shouldAutoAdopt());
    }

    public function test_clean_when_table_does_not_exist(): void
    {
        $analysis = $this->analyze(
            sql: 'CREATE TABLE users (id INT)',
            existingTables: [],
        );

        $this->assertSame(MigrationPlanItemAnalysis::Clean, $analysis->status());
        $this->assertTrue($analysis->willRunNormally());
    }

    public function test_adoptable_when_table_already_exists_and_no_missing_columns(): void
    {
        $analysis = $this->analyze(
            sql: 'CREATE TABLE users (id INT)',
            existingTables: ['users'],
        );

        $this->assertSame(MigrationPlanItemAnalysis::Adoptable, $analysis->status());
        $this->assertTrue($analysis->shouldAutoAdopt());
        $this->assertFalse($analysis->isBlocker());
    }

    public function test_clean_when_alter_table_adds_column_that_does_not_exist(): void
    {
        // Table exists (IF NOT EXISTS is fine), column doesn't exist → ADD COLUMN will succeed → run normally
        $analysis = $this->analyze(
            sql: "CREATE TABLE IF NOT EXISTS users (id INT);\nALTER TABLE users ADD COLUMN email TEXT",
            existingTables: ['users'],
            existingColumns: [],
        );

        $this->assertSame(MigrationPlanItemAnalysis::Clean, $analysis->status());
        $this->assertTrue($analysis->willRunNormally());
    }

    public function test_adoptable_when_alter_table_column_already_exists(): void
    {
        // Table exists (IF NOT EXISTS fine), column already exists → ADD COLUMN would fail → adopt
        $analysis = $this->analyze(
            sql: "CREATE TABLE IF NOT EXISTS users (id INT);\nALTER TABLE users ADD COLUMN email TEXT",
            existingTables: ['users'],
            existingColumns: ['users' => ['email']],
        );

        $this->assertSame(MigrationPlanItemAnalysis::Adoptable, $analysis->status());
        $this->assertTrue($analysis->shouldAutoAdopt());
    }

    public function test_partial_when_some_tables_exist_some_dont(): void
    {
        $analysis = $this->analyze(
            sql: "CREATE TABLE users (id INT);\nCREATE TABLE sessions (id INT)",
            existingTables: ['users'],
        );

        $this->assertSame(MigrationPlanItemAnalysis::Partial, $analysis->status());
        $this->assertTrue($analysis->isBlocker());
        $this->assertContains('users', $analysis->existingTables());
        $this->assertContains('sessions', $analysis->missingTables());
    }

    public function test_unknown_when_sql_cannot_be_extracted(): void
    {
        $analyzer = $this->analyzer(
            sqlStrings: [],
            existingTables: [],
        );

        $plan   = $this->plan('App\\Migrations\\NonExistentMigration20260101');
        $result = $analyzer->analyze($plan);

        $this->assertSame(
            MigrationPlanItemAnalysis::Unknown,
            $result['App\\Migrations\\NonExistentMigration20260101']->status(),
        );
    }

    public function test_adoptable_converted_from_drift_report_compatible_existing(): void
    {
        $analyzer = $this->analyzerWithModuleRegistry(MigrationDriftReport::CompatibleExisting, ['sample_table']);

        $plan   = $this->plan('App\\Migrations\\Version20260101000000');
        $result = $analyzer->analyze($plan);

        $this->assertSame(
            MigrationPlanItemAnalysis::Adoptable,
            $result['App\\Migrations\\Version20260101000000']->status(),
        );
    }

    public function test_clean_converted_from_drift_report_clean(): void
    {
        $analyzer = $this->analyzerWithModuleRegistry(MigrationDriftReport::Clean, []);

        $plan   = $this->plan('App\\Migrations\\Version20260101000000');
        $result = $analyzer->analyze($plan);

        $this->assertSame(
            MigrationPlanItemAnalysis::Clean,
            $result['App\\Migrations\\Version20260101000000']->status(),
        );
    }

    // --- helpers ---

    private function analyze(
        string $sql,
        array $existingTables = [],
        array $existingColumns = [],
    ): MigrationPlanItemAnalysis {
        $analyzer = $this->analyzer([$sql], $existingTables, $existingColumns);
        $plan     = $this->plan('App\\Migrations\\Version20260430');
        $result   = $analyzer->analyze($plan);

        return $result['App\\Migrations\\Version20260430'];
    }

    /**
     * @param string[] $sqlStrings
     * @param string[] $existingTables
     * @param array<string, string[]> $existingColumns
     */
    private function analyzer(
        array $sqlStrings = [],
        array $existingTables = [],
        array $existingColumns = [],
    ): MigrationPlanAnalyzer {
        $extractor = new class($sqlStrings) implements MigrationSqlExtractorInterface {
            public function __construct(private readonly array $sql) {}

            public function extractFromClass(string $className): array { return $this->sql; }
            public function extractFromSource(string $source): array { return $this->sql; }
        };

        $inspector = new class($existingTables, $existingColumns) implements MigrationRawInspectorInterface {
            public function __construct(
                private readonly array $existingTables,
                private readonly array $existingColumns,
            ) {}

            public function tableExistsRaw(string $table): bool
            {
                return in_array(strtolower($table), array_map('strtolower', $this->existingTables), true);
            }

            public function columnExistsRaw(string $table, string $column): bool
            {
                $cols = $this->existingColumns[strtolower($table)] ?? [];
                return in_array(strtolower($column), array_map('strtolower', $cols), true);
            }
        };

        $registry = new ModuleMigrationRegistry(
            new ModuleSchemaProviderScanner(new ModulePathResolver($this->tempDir), $this->tempDir),
            $this->tempDir,
        );

        return new MigrationPlanAnalyzer(
            $inspector,
            $extractor,
            new MigrationSqlParser(),
            $registry,
            new MigrationDriftDetector($this->noOpSchemaInspector()),
        );
    }

    private function analyzerWithModuleRegistry(string $driftStatus, array $existingTables): MigrationPlanAnalyzer
    {
        $this->writeModuleProvider();
        $this->writeManifest();

        $schemaInspector = new class($driftStatus, $existingTables) implements MigrationSchemaInspectorInterface {
            public function __construct(
                private readonly string $driftStatus,
                private readonly array $existingTables,
            ) {}

            public function existingTables(ModuleMigrationDescriptor $d): array
            {
                return $this->driftStatus === MigrationDriftReport::CompatibleExisting
                    ? $this->existingTables
                    : [];
            }

            public function missingTables(ModuleMigrationDescriptor $d): array
            {
                return $this->driftStatus === MigrationDriftReport::Clean
                    ? $this->existingTables
                    : [];
            }

            public function existingIndexes(ModuleMigrationDescriptor $d): array { return []; }
            public function missingIndexes(ModuleMigrationDescriptor $d): array { return []; }
            public function missingColumns(ModuleMigrationDescriptor $d): array { return []; }
        };

        $rawInspector = new class implements MigrationRawInspectorInterface {
            public function tableExistsRaw(string $table): bool { return false; }
            public function columnExistsRaw(string $table, string $column): bool { return false; }
        };

        $extractor = new class implements MigrationSqlExtractorInterface {
            public function extractFromClass(string $c): array { return []; }
            public function extractFromSource(string $s): array { return []; }
        };

        $registry = new ModuleMigrationRegistry(
            new ModuleSchemaProviderScanner(new ModulePathResolver($this->tempDir), $this->tempDir),
            $this->tempDir,
        );

        return new MigrationPlanAnalyzer(
            $rawInspector,
            $extractor,
            new MigrationSqlParser(),
            $registry,
            new MigrationDriftDetector($schemaInspector),
        );
    }

    private function noOpSchemaInspector(): MigrationSchemaInspectorInterface
    {
        return new class implements MigrationSchemaInspectorInterface {
            public function existingTables(ModuleMigrationDescriptor $d): array { return []; }
            public function missingTables(ModuleMigrationDescriptor $d): array { return []; }
            public function existingIndexes(ModuleMigrationDescriptor $d): array { return []; }
            public function missingIndexes(ModuleMigrationDescriptor $d): array { return []; }
            public function missingColumns(ModuleMigrationDescriptor $d): array { return []; }
        };
    }

    private function plan(string $versionClass): MigrationPlanList
    {
        return new MigrationPlanList([
            new MigrationPlan(
                new Version($versionClass),
                $this->createMock(AbstractMigration::class),
                Direction::UP,
            ),
        ], Direction::UP);
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

    private function writeModuleProvider(): void
    {
        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode(['packages' => [[
                'name' => 'vortos/vortos-sample',
                'install-path' => '../../packages/Vortos/src/Sample',
            ]]]),
        );

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
        $t = $schema->createTable('sample_table');
        $t->addColumn('id', 'integer');
        $t->setPrimaryKey(['id']);
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
