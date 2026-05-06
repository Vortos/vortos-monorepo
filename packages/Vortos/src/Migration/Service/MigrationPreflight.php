<?php

declare(strict_types=1);

namespace Vortos\Migration\Service;

use Doctrine\Migrations\Metadata\MigrationPlanList;
use Vortos\Migration\Schema\MigrationDriftReport;

final class MigrationPreflight
{
    public function __construct(
        private readonly ModuleMigrationRegistry $moduleRegistry,
        private readonly MigrationDriftDetector $driftDetector,
    ) {
    }

    /**
     * @return array<string, MigrationDriftReport> keyed by migration class
     */
    public function blockingReportsForPlan(MigrationPlanList $plan): array
    {
        $descriptors = $this->moduleRegistry->descriptorsByClass();
        $reports = [];

        foreach ($plan->getItems() as $item) {
            $version = (string) $item->getVersion();
            $descriptor = $descriptors[$version] ?? null;

            if ($descriptor === null) {
                continue;
            }

            $report = $this->driftDetector->detect($descriptor);

            if ($report->blocksMigration()) {
                $reports[$version] = $report;
            }
        }

        return $reports;
    }
}
