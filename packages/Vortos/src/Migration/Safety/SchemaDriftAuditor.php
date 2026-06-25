<?php

declare(strict_types=1);

namespace Vortos\Migration\Safety;

use Vortos\Migration\Schema\MigrationDriftReport;
use Vortos\Migration\Service\MigrationDriftDetectorInterface;
use Vortos\Migration\Service\ModuleMigrationRegistryInterface;

final class SchemaDriftAuditor implements SchemaDriftAuditorInterface
{
    public function __construct(
        private readonly ModuleMigrationRegistryInterface $moduleRegistry,
        private readonly MigrationDriftDetectorInterface $driftDetector,
    ) {}

    /** @return list<SchemaDriftFinding> */
    public function audit(): array
    {
        $findings = [];
        $descriptors = $this->moduleRegistry->descriptorsByClass();

        foreach ($descriptors as $descriptor) {
            try {
                $report = $this->driftDetector->detect($descriptor);
            } catch (\Throwable $e) {
                $findings[] = new SchemaDriftFinding(
                    module: $descriptor->module(),
                    hasDrift: true,
                    unreachable: true,
                    detail: sprintf('Failed to detect drift: %s', $e->getMessage()),
                );
                continue;
            }

            if ($report->status() === MigrationDriftReport::Partial) {
                $findings[] = new SchemaDriftFinding(
                    module: $descriptor->module(),
                    hasDrift: true,
                    unreachable: false,
                    detail: $this->formatDriftDetail($report),
                );
            }
        }

        return $findings;
    }

    public function hasDrift(): bool
    {
        foreach ($this->audit() as $finding) {
            if ($finding->hasDrift) {
                return true;
            }
        }

        return false;
    }

    private function formatDriftDetail(MigrationDriftReport $report): string
    {
        $parts = [];

        $missing = $report->missingTables();
        if ($missing !== []) {
            $parts[] = sprintf('missing tables: [%s]', implode(', ', $missing));
        }

        $missingIndexes = $report->missingIndexes();
        if ($missingIndexes !== []) {
            $parts[] = sprintf('missing indexes: [%s]', implode(', ', $missingIndexes));
        }

        foreach ($report->missingColumns() as $table => $cols) {
            $parts[] = sprintf('missing columns on %s: [%s]', $table, implode(', ', $cols));
        }

        return implode('; ', $parts) ?: 'schema drift detected';
    }
}
