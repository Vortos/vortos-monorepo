<?php

declare(strict_types=1);

namespace Vortos\Migration\Access;

interface ModuleTableResolverInterface
{
    /**
     * The schema-qualified tables ("vortos.backup_catalog") the named framework modules create.
     *
     * @param list<string> $modules
     * @return list<string> sorted
     * @throws \InvalidArgumentException when a module creates no table — a misspelt module must never grant nothing silently
     */
    public function tablesOf(array $modules): array;
}
