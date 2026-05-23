<?php

declare(strict_types=1);

namespace Vortos\Migration\Service;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Sequence;
use Doctrine\DBAL\Schema\Table;
use Vortos\Migration\Schema\MigrationOwnership;

/**
 * Wraps Doctrine Schema to intercept and record schema objects declared in a migration's up() method.
 * Extends Schema so it can be passed wherever Schema is expected. No SQL is executed.
 */
final class RecordingSchema extends Schema
{
    /** @var list<string> */
    private array $capturedTables = [];

    /** @var list<string> */
    private array $capturedIndexes = [];

    public function createTable(string $name): Table
    {
        $this->capturedTables[] = $name;
        return parent::createTable($name);
    }

    public function capturedOwnership(): MigrationOwnership
    {
        $indexes = [];

        foreach ($this->capturedTables as $tableName) {
            if ($this->hasTable($tableName)) {
                foreach ($this->getTable($tableName)->getIndexes() as $index) {
                    $indexes[] = $index->getName();
                }
            }
        }

        return new MigrationOwnership($this->capturedTables, array_merge($this->capturedIndexes, $indexes));
    }

    public function hasCapturedObjects(): bool
    {
        return $this->capturedTables !== [];
    }
}
