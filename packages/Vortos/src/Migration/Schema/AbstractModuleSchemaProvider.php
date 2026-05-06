<?php

declare(strict_types=1);

namespace Vortos\Migration\Schema;

use Doctrine\DBAL\Schema\Schema;

abstract class AbstractModuleSchemaProvider implements ModuleSchemaProviderInterface
{
    final public function ownership(): MigrationOwnership
    {
        $schema = new Schema();
        $this->define($schema);

        $tables = [];
        $indexes = [];

        foreach ($schema->getTables() as $table) {
            $tables[] = $table->getName();

            foreach ($table->getIndexes() as $index) {
                if ($index->isPrimary()) {
                    continue;
                }

                $indexes[] = $index->getName();
            }
        }

        return new MigrationOwnership($tables, $indexes);
    }
}
