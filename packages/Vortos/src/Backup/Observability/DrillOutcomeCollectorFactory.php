<?php

declare(strict_types=1);

namespace Vortos\Backup\Observability;

use Psr\Clock\ClockInterface;
use Vortos\Backup\Catalog\BackupCatalogReadModelInterface;
use Vortos\Backup\Config\BackupConfigLoader;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Drill\DrillReportStoreInterface;
use Vortos\Backup\Schedule\BackupScheduleType;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;

/**
 * Builds {@see DrillOutcomeCollector} from the backup configuration.
 *
 * A factory for the same reason {@see BackupFreshnessCollectorFactory} is one, and against the same
 * trap: the catalog is written under the configured environment ('production'), not APP_ENV
 * ('prod'), and a collector wired with the latter queries an environment no row has ever carried —
 * which is how the freshness gauge came to report "no backups" on an installation with thousands of
 * artifacts.
 *
 * It also derives WHICH restore paths to publish from the declared drill schedules rather than
 * assuming both. An installation that only drills logical dumps should not carry a permanently
 * absent point-in-time series, and — more usefully — one that HAS declared a point-in-time drill
 * gets its series the moment the schedule exists, so "declared but never ran" is visible as a gap
 * rather than as nothing at all.
 */
final class DrillOutcomeCollectorFactory
{
    public function __construct(
        private readonly BackupConfigLoader $loader,
    ) {}

    public function create(
        DrillReportStoreInterface $reports,
        BackupCatalogReadModelInterface $catalog,
        ClockInterface $clock,
        ?FrameworkTelemetry $telemetry = null,
    ): DrillOutcomeCollector {
        return new DrillOutcomeCollector(
            $reports,
            $catalog,
            $clock,
            $this->loader->engines(),
            $this->loader->environment(),
            $this->drilledKinds(),
            $telemetry,
        );
    }

    /**
     * The distinct kinds the declared drill schedules prove.
     *
     * @return list<BackupKind>
     */
    private function drilledKinds(): array
    {
        $kinds = [];

        foreach ($this->loader->schedules() as $schedule) {
            if ($schedule->type !== BackupScheduleType::Drill) {
                continue;
            }
            $kinds[$schedule->kind->value] = $schedule->kind;
        }

        // No drill schedule declared at all still publishes the logical series, so a `backup:drill`
        // run by hand is not invisible.
        return $kinds === [] ? [BackupKind::LogicalFull] : array_values($kinds);
    }
}
