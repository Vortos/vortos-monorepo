<?php

declare(strict_types=1);

namespace Vortos\Migration\Service;

use Vortos\Migration\Schema\ModuleMigrationDescriptor;

interface MigrationSchemaInspectorInterface
{
    /**
     * @return string[]
     */
    public function existingTables(ModuleMigrationDescriptor $descriptor): array;

    /**
     * @return string[]
     */
    public function missingTables(ModuleMigrationDescriptor $descriptor): array;

    /**
     * @return string[]
     */
    public function existingIndexes(ModuleMigrationDescriptor $descriptor): array;

    /**
     * @return string[]
     */
    public function missingIndexes(ModuleMigrationDescriptor $descriptor): array;

    /**
     * @return array<string, string[]>
     */
    public function missingColumns(ModuleMigrationDescriptor $descriptor): array;
}
