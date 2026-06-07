<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Schema;

use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Vortos\Migration\Schema\AbstractModuleSchemaProvider;
use Vortos\Migration\Schema\MigrationOwnership;

final class AbstractModuleSchemaProviderTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset to default prefix before each test
        AbstractModuleSchemaProvider::setPrefix('vortos_');
    }

    protected function tearDown(): void
    {
        // Restore default so other tests are unaffected
        AbstractModuleSchemaProvider::setPrefix('vortos_');
    }

    public function test_t_helper_applies_current_prefix(): void
    {
        AbstractModuleSchemaProvider::setPrefix('vortos_');
        $provider = $this->makeProvider('orders');

        $ownership = $provider->ownership();

        $this->assertSame(['vortos_orders'], $ownership->tables());
    }

    public function test_t_helper_applies_schema_prefix_for_postgres(): void
    {
        AbstractModuleSchemaProvider::setPrefix('vortos.');
        $provider = $this->makeProvider('orders');

        $ownership = $provider->ownership();

        $this->assertSame(['vortos.orders'], $ownership->tables());
    }

    public function test_set_prefix_affects_all_subsequent_providers(): void
    {
        AbstractModuleSchemaProvider::setPrefix('custom_');
        $provider = $this->makeProvider('events');

        $this->assertSame(['custom_events'], $provider->ownership()->tables());
    }

    public function test_ownership_extracts_table_names_correctly(): void
    {
        AbstractModuleSchemaProvider::setPrefix('vortos_');
        $provider = $this->makeMultiTableProvider(['alpha', 'beta']);

        $tables = $provider->ownership()->tables();
        sort($tables);

        $this->assertSame(['vortos_alpha', 'vortos_beta'], $tables);
    }

    public function test_ownership_extracts_non_primary_indexes(): void
    {
        AbstractModuleSchemaProvider::setPrefix('vortos_');
        $provider = new class extends AbstractModuleSchemaProvider {
            public function module(): string { return 'Test'; }
            public function id(): string { return 'test.v1'; }
            public function description(): string { return 'test'; }
            public function define(Schema $schema): void
            {
                $t = $schema->createTable($this->t('events'));
                $t->addColumn('id', 'string');
                $t->addColumn('type', 'string');
                $t->setPrimaryKey(['id']);
                $t->addIndex(['type'], 'idx_events_type');
            }
        };

        $indexes = $provider->ownership()->indexes();

        $this->assertSame(['idx_events_type'], $indexes);
        $this->assertNotContains('primary', $indexes);
    }

    private function makeProvider(string $table): AbstractModuleSchemaProvider
    {
        return new class($table) extends AbstractModuleSchemaProvider {
            public function __construct(private readonly string $tableName) {}
            public function module(): string { return 'Test'; }
            public function id(): string { return 'test.v1'; }
            public function description(): string { return 'test'; }
            public function define(Schema $schema): void
            {
                $t = $schema->createTable($this->t($this->tableName));
                $t->addColumn('id', 'string');
                $t->setPrimaryKey(['id']);
            }
        };
    }

    /**
     * @param string[] $tables
     */
    private function makeMultiTableProvider(array $tables): AbstractModuleSchemaProvider
    {
        return new class($tables) extends AbstractModuleSchemaProvider {
            public function __construct(private readonly array $tableNames) {}
            public function module(): string { return 'Test'; }
            public function id(): string { return 'test.v1'; }
            public function description(): string { return 'test'; }
            public function define(Schema $schema): void
            {
                foreach ($this->tableNames as $name) {
                    $t = $schema->createTable($this->t($name));
                    $t->addColumn('id', 'string');
                    $t->setPrimaryKey(['id']);
                }
            }
        };
    }
}
