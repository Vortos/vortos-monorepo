<?php

declare(strict_types=1);

namespace Vortos\Backup\PgBackRest;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Throwable;
use Vortos\Backup\DR\ArchiverStatusReaderInterface;
use Vortos\Backup\Health\WalFileSizeResolverInterface;
use Vortos\Backup\Runtime\CronDueEvaluator;
use Vortos\Backup\Schedule\BackupScheduleRegistry;
use Vortos\Backup\Schedule\BackupScheduleType;

/**
 * Whether the pgBackRest repository can restore what it promises, judged from its own report.
 *
 * FOUR WAYS IT FAILS, each a page:
 *  - the repository cannot be read, or reports a non-ok status (missing stanza, no valid backup);
 *  - the newest backup is older than the declared cadence allows — a schedule that stopped firing, or
 *    backups that fail, is caught here even when nothing else noticed;
 *  - the newest FULL is older than the full cadence allows: every differential refers to one;
 *  - the archive behind the server. PostgreSQL counts a segment archived once archive_command returns 0,
 *    and pgBackRest returns 0 without pushing when its queue overflows (archive-push-queue-max) — by
 *    design, to keep the database up. The server then reports segments the repository never received:
 *    the point-in-time chain has a hole that only `verify` or a restore would otherwise find.
 *
 * Cadences come from the declared schedules, never from constants: the allowance is the longest gap
 * between two consecutive due times of the relevant crons, plus a grace for the backup itself to run.
 */
final class PgBackRestRepositoryInspector
{
    /** Segments the repository may trail the server by: async push hands over in small batches. */
    public const MAX_ARCHIVE_LAG_SEGMENTS = 2;
    private const HORIZON_DAYS = 32;

    public function __construct(
        private readonly ?PgBackRestClient $client,
        private readonly BackupScheduleRegistry $schedules,
        private readonly ArchiverStatusReaderInterface $archiver,
        private readonly WalFileSizeResolverInterface $segmentSize,
        private readonly ClockInterface $clock,
        private readonly CronDueEvaluator $evaluator = new CronDueEvaluator(),
        private readonly int $graceSeconds = 7200,
    ) {}

    public function assess(): PgBackRestRepositoryAssessment
    {
        if ($this->client === null) {
            return new PgBackRestRepositoryAssessment(PgBackRestRepositoryStatus::Undeclared, [], []);
        }

        try {
            $info = $this->client->info();
        } catch (Throwable $e) {
            return $this->failing(['cannot read the repository: ' . $e->getMessage()], []);
        }

        $now = $this->clock->now();
        $problems = [];
        $facts = ['stanza' => $info->stanza, 'repository_status' => $info->statusMessage, 'cipher' => $info->cipher, 'archive_max' => $info->archiveMax];

        if (!$info->isOk()) {
            $problems[] = sprintf('the repository reports status %d: %s', $info->statusCode, $info->statusMessage);
        }

        $newest = $info->newest();
        $newestFull = $info->newest(PgBackRestBackupType::Full);
        if ($newest === null || $newestFull === null) {
            $problems[] = 'the repository holds no valid full backup';
        } else {
            $facts += [
                'newest_backup' => $newest->label,
                'newest_backup_age_seconds' => $now->getTimestamp() - $newest->stoppedAt->getTimestamp(),
                'newest_full' => $newestFull->label,
                'newest_full_age_seconds' => $now->getTimestamp() - $newestFull->stoppedAt->getTimestamp(),
            ];
            $problems = [...$problems, ...$this->staleness($newest, $now, 'backup', static fn (BackupScheduleType $t): bool => $t->takesPgBackRestBackup())];
            $problems = [...$problems, ...$this->staleness($newestFull, $now, 'full backup', static fn (BackupScheduleType $t): bool => $t === BackupScheduleType::PgBackRestFull)];
        }

        $problems = [...$problems, ...$this->archiveLag($info, $facts)];

        return $problems === []
            ? new PgBackRestRepositoryAssessment(PgBackRestRepositoryStatus::Healthy, [], $facts)
            : $this->failing($problems, $facts);
    }

    /**
     * @param callable(BackupScheduleType): bool $relevant
     *
     * @return list<string>
     */
    private function staleness(PgBackRestBackupSet $backup, DateTimeImmutable $now, string $what, callable $relevant): array
    {
        $crons = [];
        foreach ($this->schedules->all() as $schedule) {
            if ($relevant($schedule->type)) {
                $crons[] = $schedule->cron;
            }
        }
        if ($crons === []) {
            return [];
        }

        $allowance = $this->longestGapSeconds($crons, $now) + $this->graceSeconds;
        $age = $now->getTimestamp() - $backup->stoppedAt->getTimestamp();

        return $age > $allowance
            ? [sprintf('the newest %s (%s) is %s old; its schedule allows %s', $what, $backup->label, self::duration($age), self::duration($allowance))]
            : [];
    }

    /**
     * The longest wait between two consecutive due times of any of the crons, over the coming month —
     * long enough that a weekly cron is seen twice, and a cron like "0 2 * * 1-6" shows the 48 h gap it
     * leaves over the weekend.
     *
     * @param list<string> $crons
     */
    private function longestGapSeconds(array $crons, DateTimeImmutable $now): int
    {
        $horizon = $now->modify('+' . self::HORIZON_DAYS . ' days');
        $times = [];
        foreach ($crons as $cron) {
            $at = $now;
            for ($i = 0; $i < 2000; $i++) {
                $at = $this->evaluator->nextDueAfter($cron, $at);
                if ($at > $horizon) {
                    break;
                }
                $times[] = $at->getTimestamp();
            }
        }
        sort($times);

        $longest = 0;
        for ($i = 1, $n = \count($times); $i < $n; $i++) {
            $longest = max($longest, $times[$i] - $times[$i - 1]);
        }

        // One occurrence in the horizon (or none): the cadence is at least the horizon itself.
        return $longest > 0 ? $longest : self::HORIZON_DAYS * 86_400;
    }

    /**
     * @param array<string, mixed> $facts
     *
     * @return list<string>
     */
    private function archiveLag(PgBackRestInfo $info, array &$facts): array
    {
        try {
            $server = $this->archiver->read();
            $segmentBytes = $this->segmentSize->segmentBytes();
        } catch (Throwable $e) {
            return ['cannot read what the server has archived: ' . $e->getMessage()];
        }

        $facts['server_last_archived'] = $server->lastArchivedWal;
        if ($server->lastArchivedWal === null) {
            return [];
        }
        if ($info->archiveMax === null) {
            return [sprintf('the server reports %s archived but the repository holds no WAL', $server->lastArchivedWal)];
        }

        $behind = WalFileName::parse($server->lastArchivedWal)->sequence($segmentBytes)
            - WalFileName::parse($info->archiveMax)->sequence($segmentBytes);
        $facts['archive_lag_segments'] = $behind;

        return $behind > self::MAX_ARCHIVE_LAG_SEGMENTS
            ? [sprintf(
                'the server reports %s archived but the repository\'s archive ends at %s, %d segments earlier: '
                . 'pgBackRest accepted segments it did not store (queue overflow) and point-in-time recovery has a gap',
                $server->lastArchivedWal,
                $info->archiveMax,
                $behind,
            )]
            : [];
    }

    /**
     * @param list<string>         $problems
     * @param array<string, mixed> $facts
     */
    private function failing(array $problems, array $facts): PgBackRestRepositoryAssessment
    {
        return new PgBackRestRepositoryAssessment(PgBackRestRepositoryStatus::Failing, $problems, $facts);
    }

    private static function duration(int $seconds): string
    {
        return $seconds >= 86_400 ? sprintf('%.1f d', $seconds / 86_400) : sprintf('%.1f h', $seconds / 3600);
    }
}
