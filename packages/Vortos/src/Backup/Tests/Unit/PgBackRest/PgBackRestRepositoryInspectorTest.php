<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\PgBackRest;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\DR\ArchiverStatus;
use Vortos\Backup\DR\ArchiverStatusReaderInterface;
use Vortos\Backup\Drill\Container\ExecResult;
use Vortos\Backup\Health\PgBackRestRepositoryProbe;
use Vortos\Backup\Health\WalFileSizeResolverInterface;
use Vortos\Backup\PgBackRest\PgBackRestClient;
use Vortos\Backup\PgBackRest\PgBackRestRepository;
use Vortos\Backup\PgBackRest\PgBackRestRepositoryInspector;
use Vortos\Backup\PgBackRest\PgBackRestRepositoryStatus;
use Vortos\Backup\Schedule\BackupSchedule;
use Vortos\Backup\Schedule\BackupScheduleRegistry;
use Vortos\Backup\Schedule\BackupScheduleType;
use Vortos\Backup\Tests\Support\FakePgBackRestExecutor;
use Vortos\Backup\Tests\Support\FixedClock;
use Vortos\Backup\Tests\Support\PgBackRestInfoFixture;
use Vortos\Health\Probe\ProbeStatus;

final class PgBackRestRepositoryInspectorTest extends TestCase
{
    /** Full Sunday 02:00, differential Monday–Saturday 02:00. */
    private const FULL = '0 2 * * 0';
    private const DIFF = '0 2 * * 1-6';
    /** 2026-10-12 15:15:59 UTC, the production full. */
    private const FULL_STOP = 1791818159;

    private FakePgBackRestExecutor $executor;
    private string $serverArchived = '0000000100000015000003CB';

    protected function setUp(): void
    {
        $this->executor = new FakePgBackRestExecutor();
        $this->executor->answerInfo(PgBackRestInfoFixture::production()->json());
    }

    public function testHealthyWhenFreshAndTheArchiveKeepsUp(): void
    {
        $assessment = $this->inspector('2026-10-13 01:00:00')->assess();

        self::assertSame(PgBackRestRepositoryStatus::Healthy, $assessment->status, implode('; ', $assessment->problems));
        self::assertSame('20261012-151549F', $assessment->facts['newest_full']);
        self::assertSame(0, $assessment->facts['archive_lag_segments']);
    }

    public function testAMissedDifferentialPagesOnceTheCadenceAndGraceHavePassed(): void
    {
        // Daily cadence + 2 h grace = 26 h after the newest backup.
        self::assertSame(PgBackRestRepositoryStatus::Healthy, $this->inspector('2026-10-13 17:00:00')->assess()->status);

        $assessment = $this->inspector('2026-10-13 17:30:00')->assess();
        self::assertSame(PgBackRestRepositoryStatus::Failing, $assessment->status);
        self::assertStringContainsString('the newest backup (20261012-151549F) is 1.1 d old; its schedule allows 1.1 d', $assessment->problems[0]);
    }

    public function testAStaleFullPagesEvenWhileDifferentialsArrive(): void
    {
        $this->executor->answerInfo(PgBackRestInfoFixture::production()
            ->withBackup('20261012-151549F_20261021-020000D', 'diff', self::FULL_STOP + 9 * 86400 - 10, self::FULL_STOP + 9 * 86400, '0000000100000015000003CB')
            ->json());

        $assessment = $this->inspector('2026-10-21 16:00:00')->assess();

        self::assertSame(PgBackRestRepositoryStatus::Failing, $assessment->status);
        self::assertCount(1, $assessment->problems);
        self::assertStringContainsString('the newest full backup (20261012-151549F) is 9.0 d old; its schedule allows 7.1 d', $assessment->problems[0]);
    }

    public function testSegmentsTheServerCountsArchivedButTheRepositoryNeverStoredArePaged(): void
    {
        $this->serverArchived = '0000000100000015000003D0';

        $assessment = $this->inspector('2026-10-13 01:00:00')->assess();

        self::assertSame(PgBackRestRepositoryStatus::Failing, $assessment->status);
        self::assertStringContainsString('repository\'s archive ends at 0000000100000015000003CB, 5 segments earlier', $assessment->problems[0]);
    }

    public function testAnUnreadableOrUnhealthyRepositoryPages(): void
    {
        $this->executor->answers['info'] = new ExecResult(41, '', "P00  ERROR: [041]: unable to open file '/sqoura/backup/sqoura/backup.info'\n");
        self::assertStringContainsString('cannot read the repository: pgbackrest info --output=json exited 41', $this->inspector('2026-10-13 01:00:00')->assess()->problems[0]);

        $this->executor->answerInfo(PgBackRestInfoFixture::production()->withStatus(2, 'no valid backups')->json());
        self::assertStringContainsString('reports status 2: no valid backups', $this->inspector('2026-10-13 01:00:00')->assess()->problems[0]);
    }

    public function testTheProbeWarnsWherePgBackRestIsNotDeclaredAndFailsWhereItIsFailing(): void
    {
        $undeclared = new PgBackRestRepositoryProbe(new PgBackRestRepositoryInspector(null, new BackupScheduleRegistry([]), $this->archiver(), $this->segments(), new FixedClock(new DateTimeImmutable())));
        self::assertSame(ProbeStatus::Warn, $undeclared->check()->status);

        $this->serverArchived = '0000000100000015000003D0';
        self::assertSame(ProbeStatus::Fail, (new PgBackRestRepositoryProbe($this->inspector('2026-10-13 01:00:00')))->check()->status);
    }

    private function inspector(string $now): PgBackRestRepositoryInspector
    {
        return new PgBackRestRepositoryInspector(
            new PgBackRestClient($this->executor, new PgBackRestRepository('sqoura', 'write_db', '/opt/vortos/pgbackrest.env')),
            new BackupScheduleRegistry([
                new BackupSchedule('pgbackrest-full', DatabaseEngine::Postgres, BackupKind::PhysicalBase, 'production', self::FULL, BackupScheduleType::PgBackRestFull),
                new BackupSchedule('pgbackrest-diff', DatabaseEngine::Postgres, BackupKind::PhysicalBase, 'production', self::DIFF, BackupScheduleType::PgBackRestDifferential),
            ]),
            $this->archiver(),
            $this->segments(),
            new FixedClock(new DateTimeImmutable($now)),
        );
    }

    private function archiver(): ArchiverStatusReaderInterface
    {
        $wal = $this->serverArchived;

        return new class ($wal) implements ArchiverStatusReaderInterface {
            public function __construct(private string $wal) {}
            public function read(): ArchiverStatus { return new ArchiverStatus($this->wal, new DateTimeImmutable(), null, null, $this->wal); }
        };
    }

    private function segments(): WalFileSizeResolverInterface
    {
        return new class implements WalFileSizeResolverInterface {
            public function segmentBytes(): int { return 2 << 20; }
        };
    }
}
