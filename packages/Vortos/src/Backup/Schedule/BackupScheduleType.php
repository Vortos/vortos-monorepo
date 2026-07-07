<?php

declare(strict_types=1);

namespace Vortos\Backup\Schedule;

/**
 * R8-6 (A6): which verb of the backup lifecycle a {@see BackupSchedule} drives. Previously only
 * `backup:run` was schedulable; retention and drills had to be hand-rolled. Each type maps to one
 * console verb ({@see consoleVerb()}) and one in-process lifecycle action (the worker dispatches on
 * this).
 */
enum BackupScheduleType: string
{
    case Backup = 'backup';
    case Retention = 'retention';
    case Drill = 'drill';

    /** The `vortos` console verb this type invokes (host-cron path, {@see CronFragmentGenerator}). */
    public function consoleVerb(): string
    {
        return match ($this) {
            self::Backup => 'backup:run',
            self::Retention => 'backup:retention --apply',
            self::Drill => 'backup:drill',
        };
    }
}
