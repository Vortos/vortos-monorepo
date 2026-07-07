<?php

declare(strict_types=1);

namespace Vortos\Backup\Runtime;

use Vortos\Backup\Schedule\BackupSchedule;

/**
 * Executes one due backup-lifecycle occurrence (backup / retention / drill) in-process — the seam the
 * worker dispatches through. A failure throws so the worker can back off and alert; success returns a
 * short human summary for the run log.
 */
interface BackupLifecycleRunnerInterface
{
    public function execute(BackupSchedule $schedule): string;
}
