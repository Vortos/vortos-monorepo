<?php

declare(strict_types=1);

namespace Vortos\Migration\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Vortos\Migration\Schema\ModuleMigrationDescriptor;

final class MigrationSchemaInspector implements MigrationSchemaInspectorInterface, MigrationRawInspectorInterface
{
    /** @var array<string, true>|null */
    private ?array $tableNameCache = null;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return string[]
     */
    public function existingTables(ModuleMigrationDescriptor $descriptor): array
    {
        $existing = $this->tableNameIndex();

        return array_values(array_filter(
            $descriptor->ownership()->tables(),
            static fn(string $table) => isset($existing[strtolower($table)]),
        ));
    }

    /**
     * @return string[]
     */
    public function missingTables(ModuleMigrationDescriptor $descriptor): array
    {
        $existing = $this->tableNameIndex();

        return array_values(array_filter(
            $descriptor->ownership()->tables(),
            static fn(string $table) => !isset($existing[strtolower($table)]),
        ));
    }

    /**
     * @return string[]
     */
    public function existingIndexes(ModuleMigrationDescriptor $descriptor): array
    {
        $expected = array_fill_keys(array_map('strtolower', $descriptor->ownership()->indexes()), true);
        $existing = [];

        foreach ($descriptor->ownership()->tables() as $table) {
            if (!$this->tableExists($table)) {
                continue;
            }

            foreach ($this->fetchIndexNames($table) as $indexName) {
                $name = strtolower($indexName);

                if (isset($expected[$name])) {
                    $existing[] = $indexName;
                }
            }
        }

        $existing = array_values(array_unique($existing));
        sort($existing);

        return $existing;
    }

    /**
     * @return string[]
     */
    public function missingIndexes(ModuleMigrationDescriptor $descriptor): array
    {
        $existing = array_fill_keys(array_map('strtolower', $this->existingIndexes($descriptor)), true);

        return array_values(array_filter(
            $descriptor->ownership()->indexes(),
            static fn(string $index) => !isset($existing[strtolower($index)]),
        ));
    }

    /**
     * @return array<string, string[]>
     */
    public function missingColumns(ModuleMigrationDescriptor $descriptor): array
    {
        $provider = $descriptor->provider();

        if ($provider === null) {
            return [];
        }

        $schema = new Schema();
        $provider->define($schema);
        $missing = [];

        foreach ($schema->getTables() as $table) {
            $tableName = $table->getName();

            if (!$this->tableExists($tableName)) {
                continue;
            }

            $actualColumns = array_fill_keys($this->fetchColumnNames($tableName), true);

            foreach ($table->getColumns() as $column) {
                if (!isset($actualColumns[strtolower($column->getName())])) {
                    $missing[$tableName][] = $column->getName();
                }
            }
        }

        return $missing;
    }

    public function tableExistsRaw(string $table): bool
    {
        return $this->tableExists($table);
    }

    public function columnExistsRaw(string $table, string $column): bool
    {
        if (!$this->tableExists($table)) {
            return false;
        }

        return in_array(strtolower($column), $this->fetchColumnNames($table), true);
    }

    private function tableExists(string $table): bool
    {
        return isset($this->tableNameIndex()[strtolower($table)]);
    }

    /**
     * @return string[]
     */
    private function fetchIndexNames(string $qualifiedTable): array
    {
        [$schema, $table] = $this->splitTableName($qualifiedTable);

        if ($schema !== null && $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return $this->connection->fetchFirstColumn(
                'SELECT indexname FROM pg_indexes WHERE schemaname = ? AND tablename = ?',
                [$schema, $table],
            );
        }

        return array_keys($this->connection->createSchemaManager()->listTableIndexes($table));
    }

    /**
     * @return string[]  lowercase column names
     */
    private function fetchColumnNames(string $qualifiedTable): array
    {
        [$schema, $table] = $this->splitTableName($qualifiedTable);

        if ($schema !== null && $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return array_map('strtolower', $this->connection->fetchFirstColumn(
                'SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ?',
                [$schema, $table],
            ));
        }

        return array_map(
            'strtolower',
            array_keys($this->connection->createSchemaManager()->listTableColumns($table)),
        );
    }

    /**
     * Splits 'schema.table' into ['schema', 'table'], or [null, 'table'] for unqualified names.
     *
     * @return array{0: string|null, 1: string}
     */
    private function splitTableName(string $qualifiedTable): array
    {
        if (str_contains($qualifiedTable, '.')) {
            [$schema, $table] = explode('.', $qualifiedTable, 2);
            return [$schema, $table];
        }

        return [null, $qualifiedTable];
    }

    /**
     * @return array<string, true>
     */
    private function tableNameIndex(): array
    {
        if ($this->tableNameCache === null) {
            $names = array_map('strtolower', $this->connection->createSchemaManager()->listTableNames());

            // On PostgreSQL, framework tables live in the 'vortos' schema and are not returned
            // by listTableNames() (which lists the current search_path schema only).
            // Augment the index with schema-qualified names so drift detection works correctly.
            if ($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
                $schemaNames = $this->connection->fetchFirstColumn(
                    "SELECT 'vortos.' || table_name FROM information_schema.tables WHERE table_schema = 'vortos'",
                );
                $names = array_merge($names, array_map('strtolower', $schemaNames));
            }

            $this->tableNameCache = array_fill_keys($names, true);
        }

        return $this->tableNameCache;
    }
}
