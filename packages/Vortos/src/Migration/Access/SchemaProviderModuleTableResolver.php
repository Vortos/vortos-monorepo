<?php

declare(strict_types=1);

namespace Vortos\Migration\Access;

use Vortos\Migration\Service\ModuleSchemaProviderScanner;

/**
 * Resolves a module's tables from the schema providers that create them — the same declarations the migrations
 * are generated from, so the backup role's writable set follows the framework's schema instead of a hand list
 * that would silently stop covering a table a later release adds.
 */
final class SchemaProviderModuleTableResolver implements ModuleTableResolverInterface
{
    public function __construct(private readonly ModuleSchemaProviderScanner $scanner) {}

    public function tablesOf(array $modules): array
    {
        if ($modules === []) {
            return [];
        }

        $byModule = array_fill_keys($modules, []);
        foreach ($this->scanner->scan() as $entry) {
            if (!\array_key_exists($entry['module'], $byModule)) {
                continue;
            }
            foreach ($entry['provider']->ownership()->tables() as $table) {
                $byModule[$entry['module']][] = str_contains($table, '.') ? $table : 'public.' . $table;
            }
        }

        $tables = [];
        foreach ($byModule as $module => $found) {
            if ($found === []) {
                throw new \InvalidArgumentException(sprintf(
                    'Module "%s" is declared backup-writable but no schema provider of that module creates a table.',
                    $module,
                ));
            }
            array_push($tables, ...$found);
        }

        $tables = array_values(array_unique($tables));
        foreach ($tables as $table) {
            if (preg_match('/^[a-z_][a-z0-9_]*\.[a-z_][a-z0-9_]*$/', $table) !== 1) {
                throw new \InvalidArgumentException(sprintf('Refusing to grant on table name "%s": not a plain schema.table identifier.', $table));
            }
        }
        sort($tables);

        return $tables;
    }
}
