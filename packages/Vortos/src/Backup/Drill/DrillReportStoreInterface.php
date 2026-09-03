<?php

declare(strict_types=1);

namespace Vortos\Backup\Drill;

use Vortos\Backup\Domain\BackupKind;

interface DrillReportStoreInterface
{
    public function save(DrillReport $report): void;

    public function latest(string $engine, string $environment): ?DrillReport;

    /**
     * The newest report for ONE restore path.
     *
     * Separate from {@see latest()} because "the last drill" is ambiguous once an installation runs
     * more than one: a daily logical drill would mask a weekly point-in-time drill that has been
     * failing for a month, since the logical one is always the more recent row.
     */
    public function latestOfKind(string $engine, string $environment, BackupKind $kind): ?DrillReport;
}
