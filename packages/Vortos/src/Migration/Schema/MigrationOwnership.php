<?php

declare(strict_types=1);

namespace Vortos\Migration\Schema;

final class MigrationOwnership
{
    /**
     * @param string[] $tables
     * @param string[] $indexes
     */
    public function __construct(
        private readonly array $tables = [],
        private readonly array $indexes = [],
    ) {
    }

    /**
     * @return string[]
     */
    public function tables(): array
    {
        $tables = array_values(array_unique($this->tables));
        sort($tables);

        return $tables;
    }

    /**
     * @return string[]
     */
    public function indexes(): array
    {
        $indexes = array_values(array_unique($this->indexes));
        sort($indexes);

        return $indexes;
    }

    /**
     * @return array{tables: string[], indexes: string[]}
     */
    public function toArray(): array
    {
        return [
            'tables' => $this->tables(),
            'indexes' => $this->indexes(),
        ];
    }
}
