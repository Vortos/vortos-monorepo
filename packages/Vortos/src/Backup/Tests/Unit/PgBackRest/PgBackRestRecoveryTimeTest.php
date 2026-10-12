<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\PgBackRest;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Backup\Catalog\WalVolumeReadModelInterface;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\DR\ArchiverStatus;
use Vortos\Backup\DR\ArchiverStatusReaderInterface;
use Vortos\Backup\DR\ObjectiveStatus;
use Vortos\Backup\DR\RecoveryObjectives;
use Vortos\Backup\DR\RecoveryObjectivesInspector;
use Vortos\Backup\Drill\DrillOutcome;
use Vortos\Backup\Drill\DrillReport;
use Vortos\Backup\Drill\InvariantResult;
use Vortos\Backup\Health\WalFileSizeResolverInterface;
use Vortos\Backup\PgBackRest\PgBackRestClient;
use Vortos\Backup\PgBackRest\PgBackRestPitrAnchorReader;
use Vortos\Backup\PgBackRest\PgBackRestRepository;
use Vortos\Backup\Pitr\PitrRecoveryOutcome;
use Vortos\Backup\Schedule\BackupSchedule;
use Vortos\Backup\Schedule\BackupScheduleRegistry;
use Vortos\Backup\Schedule\BackupScheduleType;
use Vortos\Backup\Tests\Support\FakePgBackRestExecutor;
use Vortos\Backup\Tests\Support\FixedClock;
use Vortos\Backup\Tests\Support\InMemoryCatalogRepository;
use Vortos\Backup\Tests\Support\InMemoryDrillReportStore;
use Vortos\Backup\Tests\Support\PgBackRestInfoFixture;

/**
 * The recovery-time projection anchored on pgBackRest: the newest backup in its repository, the WAL
 * archived since, and the next pgBackRest backup as the point where the chain resets. The framework's
 * own catalog is empty here on purpose — after the cutover it holds no physical base at all.
 */
final class PgBackRestRecoveryTimeTest extends TestCase
{
    public function testProjectsFromTheRepositoryAndResetsAtTheNextPgBackRestBackup(): void
    {
        $executor = new FakePgBackRestExecutor();
        // Full stopped 15:15:59 in ...3C4; archive now ends at ...3CB: 7 segments to replay.
        $executor->answerInfo(PgBackRestInfoFixture::production()->json());

        $drills = new InMemoryDrillReportStore();
        $evidence = new PitrRecoveryOutcome(10, '15/78801330', '15/79601330', '0000000100000015000003CB', true, 1_000);
        $drills->save(new DrillReport('d', DatabaseEngine::Postgres, 'production', 'pgbackrest:20261012-151549F', new DateTimeImmutable('2026-10-12 16:00:00'), 31_000, DrillOutcome::Passed, [InvariantResult::pass('wal_replayed', $evidence->summary())], null, BackupKind::PhysicalBase));

        $inspector = new RecoveryObjectivesInspector(
            new RecoveryObjectives(300, 1800),
            new class implements ArchiverStatusReaderInterface {
                public function read(): ArchiverStatus { return new ArchiverStatus('0000000100000015000003CB', new DateTimeImmutable(), null, null, '0000000100000015000003CC'); }
            },
            new InMemoryCatalogRepository(),
            new class implements WalVolumeReadModelInterface {
                public function walVolumeSince(DatabaseEngine $engine, string $environment, DateTimeImmutable $from): array { throw new \LogicException('the catalog is not the anchor here'); }
                public function newestWalSegmentName(DatabaseEngine $engine, string $environment): ?string { return null; }
            },
            $drills,
            new BackupScheduleRegistry([
                new BackupSchedule('pgbackrest-full', DatabaseEngine::Postgres, BackupKind::PhysicalBase, 'production', '0 2 * * 0', BackupScheduleType::PgBackRestFull),
                new BackupSchedule('pgbackrest-diff', DatabaseEngine::Postgres, BackupKind::PhysicalBase, 'production', '0 2 * * 1-6', BackupScheduleType::PgBackRestDifferential),
            ]),
            new FixedClock(new DateTimeImmutable('2026-10-12 16:00:00 UTC')),
            'production',
            anchors: new PgBackRestPitrAnchorReader(
                new PgBackRestClient($executor, new PgBackRestRepository('sqoura', 'write_db', '/opt/vortos/pgbackrest.env')),
                new class implements WalFileSizeResolverInterface {
                    public function segmentBytes(): int { return 2 << 20; }
                },
            ),
        );

        $assessment = $inspector->recoveryTime();

        self::assertSame(ObjectiveStatus::Met, $assessment->status, $assessment->reason);
        self::assertSame(7, $assessment->segmentsSinceAnchor);
        self::assertSame('2026-10-12 15:15:59', $assessment->anchorAt?->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-13 02:00:00', $assessment->nextAnchorAt?->format('Y-m-d H:i:s'), 'the next differential resets the chain');
        // 30 s fixed + 100 ms per segment x 7.
        self::assertSame(31, $assessment->projectedSeconds);
    }
}
