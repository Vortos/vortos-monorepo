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
    /**
     * pgBackRest's lifecycle (config/backup.php ->pgBackRest()). Retention is not among them: pgBackRest
     * expires what its retention no longer needs as the last step of every backup.
     */
    case PgBackRestFull = 'pgbackrest_full';
    case PgBackRestDifferential = 'pgbackrest_diff';
    case PgBackRestVerify = 'pgbackrest_verify';
    case PgBackRestDrill = 'pgbackrest_drill';

    /** The `vortos` console verb this type invokes (host-cron path, {@see CronFragmentGenerator}). */
    public function consoleVerb(): string
    {
        return match ($this) {
            self::Backup => 'backup:run',
            self::Retention => 'backup:retention --apply',
            self::Drill => 'backup:drill',
            self::PgBackRestFull => 'backup:pgbackrest backup --type=full',
            self::PgBackRestDifferential => 'backup:pgbackrest backup --type=diff',
            self::PgBackRestVerify => 'backup:pgbackrest verify',
            self::PgBackRestDrill => 'backup:pgbackrest drill',
        };
    }

    public function isPgBackRest(): bool
    {
        return match ($this) {
            self::PgBackRestFull, self::PgBackRestDifferential, self::PgBackRestVerify, self::PgBackRestDrill => true,
            self::Backup, self::Retention, self::Drill => false,
        };
    }

    /** A restore drill, by either restore path. */
    public function isDrill(): bool
    {
        return $this === self::Drill || $this === self::PgBackRestDrill;
    }

    /** A schedule that lays down a new point-in-time recovery anchor. */
    public function takesPgBackRestBackup(): bool
    {
        return $this === self::PgBackRestFull || $this === self::PgBackRestDifferential;
    }
}
