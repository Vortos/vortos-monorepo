<?php

declare(strict_types=1);

namespace Vortos\Backup\Runtime;

use Vortos\Backup\Schedule\BackupSchedule;

/**
 * Executes one due backup-lifecycle occurrence (backup / retention / drill) in-process — the seam the
 * worker dispatches through. A failure throws so the worker can back off and alert.
 *
 * Returns a {@see LifecycleOutcome} rather than a summary string because "did nothing, the lock was
 * held" is a distinct result from "ran to completion", and only the latter may advance the schedule's
 * watermark. Collapsing the two cost production four days of logical backups.
 */
interface BackupLifecycleRunnerInterface
{
    public function execute(BackupSchedule $schedule): LifecycleOutcome;
}
