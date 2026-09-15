<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Pitr;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Vortos\Backup\DR\ArchiverStatus;
use Vortos\Backup\DR\ArchiverStatusReaderInterface;
use Vortos\Backup\Pitr\CheckpointerTimers;
use Vortos\Backup\Pitr\CheckpointerTimersReaderInterface;
use Vortos\Backup\Pitr\CheckpointerWakeOutcome;
use Vortos\Backup\Pitr\CheckpointerWaker;
use Vortos\Backup\Pitr\CheckpointRequesterInterface;

/**
 * RC-10 guard: the checkpointer is woken exactly when PostgreSQL 18 has stranded archive_timeout after a start —
 * and never otherwise. The production shape is the one measured on 2026-09-15: started 08:55:39, last archive at
 * the shutdown (08:55:39), no checkpoint since the start, nothing archived for 22 minutes.
 */
final class CheckpointerWakerTest extends TestCase
{
    private const START = '2026-09-15 08:55:39';

    private int $checkpoints = 0;

    private function waker(
        ?CheckpointerTimers $timers = null,
        ?ArchiverStatus $status = null,
        bool $refuse = false,
        bool $unreadable = false,
    ): CheckpointerWaker {
        $timers ??= self::timers();
        $status ??= self::archiverStatus(lastArchivedAt: self::START);

        $timersReader = new class ($timers, $unreadable) implements CheckpointerTimersReaderInterface {
            public function __construct(private CheckpointerTimers $t, private bool $unreadable) {}

            public function read(): CheckpointerTimers
            {
                return $this->unreadable ? throw new RuntimeException('SQLSTATE[08006] postgresql://u:secret@db') : $this->t;
            }
        };
        $archiver = new class ($status) implements ArchiverStatusReaderInterface {
            public function __construct(private ArchiverStatus $s) {}

            public function read(): ArchiverStatus
            {
                return $this->s;
            }
        };
        $requester = new class ($this, $refuse) implements CheckpointRequesterInterface {
            public function __construct(private CheckpointerWakerTest $test, private bool $refuse) {}

            public function checkpoint(): void
            {
                if ($this->refuse) {
                    throw new RuntimeException('permission denied to execute CHECKPOINT');
                }
                $this->test->recordCheckpoint();
            }
        };

        return new CheckpointerWaker($timersReader, $archiver, $requester);
    }

    public function recordCheckpoint(): void
    {
        $this->checkpoints++;
    }

    private static function timers(
        bool $archiving = true,
        int $timeout = 60,
        bool $inRecovery = false,
        string $start = self::START,
        string $lastCheckpoint = self::START,
    ): CheckpointerTimers {
        return new CheckpointerTimers($archiving, $timeout, $inRecovery, new DateTimeImmutable($start . ' UTC'), new DateTimeImmutable($lastCheckpoint . ' UTC'));
    }

    private static function archiverStatus(
        ?string $lastArchivedAt,
        ?string $lastArchivedWal = '0000000100000001000007C0',
        ?string $currentWal = '0000000100000001000007C1',
        ?string $lastFailedAt = null,
    ): ArchiverStatus {
        return new ArchiverStatus(
            $lastArchivedWal,
            $lastArchivedAt === null ? null : new DateTimeImmutable($lastArchivedAt . ' UTC'),
            $lastFailedAt === null ? null : '0000000100000001000007C1',
            $lastFailedAt === null ? null : new DateTimeImmutable($lastFailedAt . ' UTC'),
            $currentWal,
        );
    }

    private static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time . ' UTC');
    }

    /** The production incident: a clean start whose checkpointer slept through archive_timeout. */
    public function test_the_stranded_timer_after_a_clean_start_is_woken_with_one_checkpoint(): void
    {
        // lastCheckpoint = the shutdown checkpoint, a moment BEFORE this postmaster started.
        $waker = $this->waker(timers: self::timers(lastCheckpoint: '2026-09-15 08:55:38'));

        $result = $waker->ensure(self::at('2026-09-15 09:08:00'));

        self::assertSame(CheckpointerWakeOutcome::Woken, $result->outcome);
        self::assertSame(741, $result->exposureSeconds);
        self::assertSame(1, $this->checkpoints);
    }

    public function test_postgres_gets_its_full_archive_timeout_plus_grace_after_a_start_before_it_is_judged_stranded(): void
    {
        $waker = $this->waker(timers: self::timers(lastCheckpoint: '2026-09-15 08:55:38'));

        self::assertSame(CheckpointerWakeOutcome::Honoured, $waker->ensure(self::at('2026-09-15 08:57:39'))->outcome, 'exactly archive_timeout + grace');
        self::assertSame(CheckpointerWakeOutcome::Woken, $waker->ensure(self::at('2026-09-15 08:57:40'))->outcome);
    }

    /** A cluster that was down for hours has an old last archive; the timer cannot have run before the start. */
    public function test_an_old_last_archive_is_measured_from_the_start_not_from_before_it(): void
    {
        $waker = $this->waker(
            timers: self::timers(lastCheckpoint: '2026-09-15 06:00:00'),
            status: self::archiverStatus(lastArchivedAt: '2026-09-15 06:00:00'),
        );

        self::assertSame(CheckpointerWakeOutcome::Honoured, $waker->ensure(self::at('2026-09-15 08:56:30'))->outcome);
        self::assertSame(0, $this->checkpoints);
    }

    public function test_once_a_checkpoint_has_run_since_the_start_it_never_checkpoints_again(): void
    {
        $waker = $this->waker(timers: self::timers(lastCheckpoint: '2026-09-15 08:56:00'));

        $result = $waker->ensure(self::at('2026-09-15 09:30:00'));

        self::assertSame(CheckpointerWakeOutcome::AlreadyAwake, $result->outcome, 'an idle cluster must not be checkpointed every tick');
        self::assertSame(0, $this->checkpoints);
    }

    public function test_nothing_is_done_while_postgres_is_switching_segments_itself(): void
    {
        $waker = $this->waker(
            timers: self::timers(lastCheckpoint: '2026-09-15 08:55:38'),
            status: self::archiverStatus(lastArchivedAt: '2026-09-15 09:07:10'),
        );

        self::assertSame(CheckpointerWakeOutcome::Honoured, $waker->ensure(self::at('2026-09-15 09:08:00'))->outcome);
        self::assertSame(0, $this->checkpoints);
    }

    public function test_nothing_is_unarchived_so_nothing_is_stranded(): void
    {
        $waker = $this->waker(status: self::archiverStatus(lastArchivedAt: '2026-09-15 08:00:00', currentWal: '0000000100000001000007C0'));

        self::assertSame(CheckpointerWakeOutcome::Honoured, $waker->ensure(self::at('2026-09-15 10:00:00'))->outcome);
    }

    /** @return iterable<string, array{CheckpointerTimers}> */
    public static function noTimerToKeep(): iterable
    {
        yield 'archiving off' => [self::timers(archiving: false, lastCheckpoint: '2026-09-15 08:55:38')];
        yield 'archive_timeout 0' => [self::timers(timeout: 0, lastCheckpoint: '2026-09-15 08:55:38')];
        yield 'standby' => [self::timers(inRecovery: true, lastCheckpoint: '2026-09-15 08:55:38')];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('noTimerToKeep')]
    public function test_there_is_no_timer_to_keep(CheckpointerTimers $timers): void
    {
        self::assertSame(CheckpointerWakeOutcome::NotApplicable, $this->waker(timers: $timers)->ensure(self::at('2026-09-15 10:00:00'))->outcome);
        self::assertSame(0, $this->checkpoints);
    }

    public function test_a_failing_archive_command_is_reported_not_forced(): void
    {
        $waker = $this->waker(
            timers: self::timers(lastCheckpoint: '2026-09-15 08:55:38'),
            status: self::archiverStatus(lastArchivedAt: self::START, lastFailedAt: '2026-09-15 09:07:00'),
        );

        $result = $waker->ensure(self::at('2026-09-15 09:08:00'));

        self::assertSame(CheckpointerWakeOutcome::ArchivingFailing, $result->outcome);
        self::assertTrue($result->outcome->isReportable());
        self::assertSame(0, $this->checkpoints);
    }

    public function test_a_refused_checkpoint_is_reported_by_exception_class_only(): void
    {
        $result = $this->waker(timers: self::timers(lastCheckpoint: '2026-09-15 08:55:38'), refuse: true)->ensure(self::at('2026-09-15 09:08:00'));

        self::assertSame(CheckpointerWakeOutcome::WakeFailed, $result->outcome);
        self::assertSame(RuntimeException::class, $result->errorClass);
        self::assertStringContainsString('pg_checkpoint', $result->summary());
    }

    public function test_an_unreadable_server_decides_nothing_and_leaks_nothing(): void
    {
        $result = $this->waker(unreadable: true)->ensure(self::at('2026-09-15 09:08:00'));

        self::assertSame(CheckpointerWakeOutcome::Indeterminate, $result->outcome);
        self::assertFalse($result->outcome->isReportable(), 'a non-Postgres or unreachable connection must stay silent on every tick');
        self::assertStringNotContainsString('secret', $result->summary());
        self::assertSame(0, $this->checkpoints);
    }
}
