<?php

declare(strict_types=1);

namespace Vortos\Migration\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Vortos\Migration\Schema\ModuleMigrationDescriptor;

final class MigrationSchemaInspector implements MigrationSchemaInspectorInterface
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

            foreach ($this->connection->createSchemaManager()->listTableIndexes($table) as $index) {
                $name = strtolower($index->getName());

                if (isset($expected[$name])) {
                    $existing[] = $index->getName();
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

            $actualColumns = array_fill_keys(
                array_map('strtolower', array_keys($this->connection->createSchemaManager()->listTableColumns($tableName))),
                true,
            );

            foreach ($table->getColumns() as $column) {
                if (!isset($actualColumns[strtolower($column->getName())])) {
                    $missing[$tableName][] = $column->getName();
                }
            }
        }

        return $missing;
    }

    private function tableExists(string $table): bool
    {
        return isset($this->tableNameIndex()[strtolower($table)]);
    }

    /**
     * @return array<string, true>
     */
    private function tableNameIndex(): array
    {
        if ($this->tableNameCache === null) {
            $this->tableNameCache = array_fill_keys(
                array_map('strtolower', $this->connection->createSchemaManager()->listTableNames()),
                true,
            );
        }

        return $this->tableNameCache;
    }
}
