<?php

declare(strict_types=1);

namespace Vortos\Backup\Drill;

interface DrillReportStoreInterface
{
    public function save(DrillReport $report): void;

    public function latest(string $engine, string $environment): ?DrillReport;
}
