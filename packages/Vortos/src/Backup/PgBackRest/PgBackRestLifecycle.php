<?php

declare(strict_types=1);

namespace Vortos\Backup\PgBackRest;

use RuntimeException;
use Vortos\Backup\Runtime\LifecycleOutcome;
use Vortos\Backup\Schedule\BackupSchedule;
use Vortos\Backup\Schedule\BackupScheduleType;

/**
 * What the backup worker does when a pgBackRest schedule is due. Failures throw, so the worker's
 * bounded retry and its dead-man `backup.failed` alert cover pgBackRest exactly as they cover the rest.
 */
final class PgBackRestLifecycle
{
    public function __construct(
        private readonly PgBackRestClient $client,
        private readonly PgBackRestDrill $drill,
    ) {}

    public function execute(BackupSchedule $schedule): LifecycleOutcome
    {
        return match ($schedule->type) {
            BackupScheduleType::PgBackRestFull => $this->backup(PgBackRestBackupType::Full, $schedule->repository),
            BackupScheduleType::PgBackRestDifferential => $this->backup(PgBackRestBackupType::Differential, $schedule->repository),
            BackupScheduleType::PgBackRestVerify => $this->verify($schedule->repository),
            BackupScheduleType::PgBackRestDrill => $this->drill($schedule->environment, $schedule->repository),
            default => throw new RuntimeException(sprintf('Schedule "%s" is not a pgBackRest schedule.', $schedule->name)),
        };
    }

    private function backup(PgBackRestBackupType $type, PgBackRestRepositoryRole $role): LifecycleOutcome
    {
        $this->client->backup($type, $role);
        $newest = $this->client->info($role)->newest();

        // pgBackRest promotes a differential to a full when no full exists yet — still the backup asked for.
        // Anything else means the repository does not list what was just taken.
        if ($newest === null || ($newest->type !== $type && $newest->type !== PgBackRestBackupType::Full)) {
            throw new RuntimeException(sprintf('pgbackrest backup --type=%s exited 0 but the %s repository lists no such backup.', $type->value, $role->value));
        }

        return LifecycleOutcome::completed(sprintf(
            'pgBackRest %s%s %s stored (%s of data, %s added to the repository)',
            $role === PgBackRestRepositoryRole::Offsite ? 'off-site ' : '',
            $newest->type->value,
            $newest->label,
            self::bytes($newest->databaseBytes),
            self::bytes($newest->repositoryDeltaBytes),
        ));
    }

    private function verify(PgBackRestRepositoryRole $role): LifecycleOutcome
    {
        $this->client->verify($role);

        return LifecycleOutcome::completed(sprintf('pgBackRest %s repository verified against its checksums', $role->value));
    }

    private function drill(string $environment, PgBackRestRepositoryRole $role): LifecycleOutcome
    {
        $report = $this->drill->run($environment, $role);

        if (!$report->restored()) {
            throw new RuntimeException(sprintf(
                'pgBackRest restore drill did not pass (outcome=%s%s)',
                $report->outcome->value,
                $report->error !== null ? ': ' . $report->error : '',
            ));
        }

        // Over objective is completed, not thrown: the restore worked, and the drill has already emitted
        // the Critical drill_over_objective event (same reasoning as BackupLifecycleRunner::runDrill()).
        return LifecycleOutcome::completed(sprintf('pgBackRest drill %s (%s, rto=%dms)', $report->outcome->value, $report->artifactId, $report->rtoMs));
    }

    private static function bytes(int $bytes): string
    {
        return $bytes >= 1 << 30
            ? sprintf('%.2f GiB', $bytes / (1 << 30))
            : sprintf('%.1f MiB', $bytes / (1 << 20));
    }
}
