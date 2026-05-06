<?php

declare(strict_types=1);

namespace Vortos\Migration\Service;

use Vortos\Migration\Schema\MigrationDriftReport;
use Vortos\Migration\Schema\ModuleMigrationDescriptor;

final class MigrationDriftDetector
{
    public function __construct(private readonly MigrationSchemaInspectorInterface $inspector)
    {
    }

    public function detect(ModuleMigrationDescriptor $descriptor): MigrationDriftReport
    {
        if ($descriptor->ownership()->tables() === [] && $descriptor->ownership()->indexes() === []) {
            return new MigrationDriftReport(MigrationDriftReport::Unknown, $descriptor);
        }

        $existingTables = $this->inspector->existingTables($descriptor);
        $missingTables = $this->inspector->missingTables($descriptor);
        $existingIndexes = $this->inspector->existingIndexes($descriptor);
        $missingIndexes = $this->inspector->missingIndexes($descriptor);
        $missingColumns = $this->inspector->missingColumns($descriptor);

        if ($existingTables === [] && $existingIndexes === []) {
            return new MigrationDriftReport(MigrationDriftReport::Clean, $descriptor, missingTables: $missingTables);
        }

        if ($missingTables === [] && $missingIndexes === [] && $missingColumns === []) {
            return new MigrationDriftReport(
                MigrationDriftReport::CompatibleExisting,
                $descriptor,
                $existingTables,
                $missingTables,
                $existingIndexes,
                $missingIndexes,
                $missingColumns,
            );
        }

        return new MigrationDriftReport(
            MigrationDriftReport::Partial,
            $descriptor,
            $existingTables,
            $missingTables,
            $existingIndexes,
            $missingIndexes,
            $missingColumns,
        );
    }
}
