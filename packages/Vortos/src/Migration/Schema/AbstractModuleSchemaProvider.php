<?php

declare(strict_types=1);

namespace Vortos\Migration\Schema;

use Doctrine\DBAL\Schema\Schema;

abstract class AbstractModuleSchemaProvider implements ModuleSchemaProviderInterface
{
    private static string $frameworkPrefix = 'vortos_';

    /**
     * Set the framework table prefix before migration providers are loaded.
     * Called by ModuleSchemaProviderScanner at scan time.
     */
    public static function setPrefix(string $prefix): void
    {
        self::$frameworkPrefix = $prefix;
    }

    /**
     * Qualify a bare framework table name with the configured prefix.
     * Use this in define() for all framework-owned tables.
     *
     * PostgreSQL example: t('user_roles') → 'vortos.user_roles'
     * Other DBs example:  t('user_roles') → 'vortos_user_roles'
     */
    protected function t(string $table): string
    {
        return self::$frameworkPrefix . $table;
    }

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
