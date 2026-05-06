<?php

declare(strict_types=1);

namespace Vortos\Migration\Service;

use Vortos\Migration\Schema\MigrationDriftReport;

final class MigrationDriftFormatter
{
    public function label(?MigrationDriftReport $report, bool $executed): string
    {
        if ($executed) {
            return 'Tracked';
        }

        if ($report === null) {
            return 'Not checked';
        }

        return match ($report->status()) {
            MigrationDriftReport::Clean => 'Clean',
            MigrationDriftReport::CompatibleExisting => 'Drift: compatible existing schema',
            MigrationDriftReport::Partial => 'Drift: partial or incompatible schema',
            MigrationDriftReport::Unknown => 'Unknown',
            default => 'Unknown',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(?MigrationDriftReport $report, bool $executed): array
    {
        return [
            'status' => $report?->status() ?? ($executed ? 'tracked' : 'not_checked'),
            'label' => $this->label($report, $executed),
            'blocks_migration' => $report?->blocksMigration() ?? false,
            'existing_tables' => $report?->existingTables() ?? [],
            'missing_tables' => $report?->missingTables() ?? [],
            'existing_indexes' => $report?->existingIndexes() ?? [],
            'missing_indexes' => $report?->missingIndexes() ?? [],
            'missing_columns' => $report?->missingColumns() ?? [],
        ];
    }
}
