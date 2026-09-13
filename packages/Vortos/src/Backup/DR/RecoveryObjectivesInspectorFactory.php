<?php

declare(strict_types=1);

namespace Vortos\Backup\DR;

use Psr\Clock\ClockInterface;
use Vortos\Backup\Catalog\BackupCatalogReadModelInterface;
use Vortos\Backup\Catalog\WalVolumeReadModelInterface;
use Vortos\Backup\Config\BackupConfigLoader;
use Vortos\Backup\Drill\DrillReportStoreInterface;
use Vortos\Backup\Runtime\CronDueEvaluator;

/**
 * Builds {@see RecoveryObjectivesInspector} from config/backup.php — objectives, schedules, and the
 * environment the catalog is written under ('production', not APP_ENV), the trap the freshness gauge
 * fell into by wiring a literal.
 */
final class RecoveryObjectivesInspectorFactory
{
    public function __construct(private readonly BackupConfigLoader $loader) {}

    public function create(
        ArchiverStatusReaderInterface $archiver,
        BackupCatalogReadModelInterface $catalog,
        WalVolumeReadModelInterface $wal,
        DrillReportStoreInterface $drills,
        ClockInterface $clock,
        CronDueEvaluator $evaluator,
    ): RecoveryObjectivesInspector {
        return new RecoveryObjectivesInspector(
            $this->loader->recoveryObjectivesOrNull(),
            $archiver,
            $catalog,
            $wal,
            $drills,
            $this->loader->scheduleRegistry(),
            $clock,
            $this->loader->environment(),
            $evaluator,
        );
    }
}
