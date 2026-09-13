<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\DR;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Vortos\Backup\Catalog\WalVolumeReadModelInterface;
use Vortos\Backup\Domain\BackupArtifact;
use Vortos\Backup\Domain\BackupChecksum;
use Vortos\Backup\Domain\BackupId;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\CompressionCodec;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Domain\SourceRef;
use Vortos\Backup\DR\ArchiverStatus;
use Vortos\Backup\DR\ArchiverStatusReaderInterface;
use Vortos\Backup\DR\ObjectiveStatus;
use Vortos\Backup\DR\RecoveryObjectives;
use Vortos\Backup\DR\RecoveryObjectivesInspector;
use Vortos\Backup\Drill\DrillOutcome;
use Vortos\Backup\Drill\DrillReport;
use Vortos\Backup\Drill\InvariantResult;
use Vortos\Backup\Pitr\PitrRecoveryOutcome;
use Vortos\Backup\Schedule\BackupSchedule;
use Vortos\Backup\Schedule\BackupScheduleRegistry;
use Vortos\Backup\Schedule\BackupScheduleType;
use Vortos\Backup\Tests\Support\FixedClock;
use Vortos\Backup\Tests\Support\InMemoryCatalogRepository;
use Vortos\Backup\Tests\Support\InMemoryDrillReportStore;

/**
 * Driven with the numbers production measured on 2026-09-13, because that is the defect this exists
 * for: base Sunday 02:00, a PITR drill at 15:33 that replayed 828 segments in 333.66 s of a 341.30 s
 * RTO, and WAL archived at exactly 60 segments an hour.
 */
final class RecoveryObjectivesInspectorTest extends TestCase
{
    private const ENV = 'production';

    private InMemoryCatalogRepository $catalog;
    private InMemoryDrillReportStore $drills;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->catalog = new InMemoryCatalogRepository();
        $this->drills = new InMemoryDrillReportStore();
        $this->now = new DateTimeImmutable('2026-09-13 15:40:00 UTC');
    }

    /**
     * The production defect. Recovery is ~5.6 minutes now, so nothing looks wrong — but a weekly base
     * lets it reach ~62 minutes by the next Sunday, crossing 30 minutes on Wednesday morning.
     */
    public function testAWeeklyBaseIsAtRiskDaysBeforeARecoveryWouldMissTheObjective(): void
    {
        $this->seedProductionEvidence();

        $rto = $this->inspector(['0 2 * * 0'])->recoveryTime();

        self::assertSame(ObjectiveStatus::AtRisk, $rto->status);
        self::assertLessThan(1800, (int) $rto->projectedSeconds, 'a recovery right now is still inside the objective');
        self::assertGreaterThan(3500, (int) $rto->projectedAtNextAnchorSeconds);
        self::assertNotNull($rto->breachAt);
        self::assertGreaterThanOrEqual(new DateTimeImmutable('2026-09-16 03:00:00 UTC'), $rto->breachAt);
        self::assertLessThanOrEqual(new DateTimeImmutable('2026-09-16 05:00:00 UTC'), $rto->breachAt);
        self::assertEqualsWithDelta(403.0, (float) $rto->replayMsPerSegment, 1.0);
        self::assertEqualsWithDelta(7637, (int) $rto->fixedMs, 1);
    }

    /** The fix: a daily base keeps the worst point of the cycle at ~10 minutes. */
    public function testADailyBaseMeetsTheObjectiveThroughTheWholeCycle(): void
    {
        $this->seedProductionEvidence();

        $rto = $this->inspector(['0 2 * * *'])->recoveryTime();

        self::assertSame(ObjectiveStatus::Met, $rto->status);
        self::assertLessThan(700, (int) $rto->projectedAtNextAnchorSeconds);
        self::assertNull($rto->breachAt, 'a crossing the next base pre-empts is not a prediction');
    }

    public function testARecoveryThatWouldAlreadyMissIsBreached(): void
    {
        $this->seedBase('2026-09-06 02:00:00');
        $this->seedPitrDrill(segments: 828, recoveryMs: 333_660, rtoMs: 341_297);

        $rto = $this->inspector(['0 2 * * 0'])->recoveryTime();

        self::assertSame(ObjectiveStatus::Breached, $rto->status);
        self::assertGreaterThan(1800, (int) $rto->projectedSeconds);
    }

    public function testNoScheduledBaseMeansTheChainNeverResets(): void
    {
        $this->seedProductionEvidence();

        $rto = $this->inspector([])->recoveryTime();

        self::assertSame(ObjectiveStatus::AtRisk, $rto->status);
        self::assertNull($rto->nextAnchorAt);
        self::assertNotNull($rto->breachAt);
    }

    public function testNoDrillMeansReplayCostIsUnmeasuredNotHealthy(): void
    {
        $this->seedBase('2026-09-13 02:00:00');

        self::assertSame(ObjectiveStatus::Indeterminate, $this->inspector(['0 2 * * *'])->recoveryTime()->status);
    }

    public function testAFailedDrillsTimingsAreNotReplayEvidence(): void
    {
        $this->seedBase('2026-09-13 02:00:00');
        $this->seedPitrDrill(segments: 828, recoveryMs: 333_660, rtoMs: 341_297, outcome: DrillOutcome::Failed);

        self::assertSame(ObjectiveStatus::Indeterminate, $this->inspector(['0 2 * * *'])->recoveryTime()->status);
    }

    public function testNoBaseBackupIsIndeterminate(): void
    {
        $this->seedPitrDrill(segments: 828, recoveryMs: 333_660, rtoMs: 341_297);

        self::assertSame(ObjectiveStatus::Indeterminate, $this->inspector(['0 2 * * *'])->recoveryTime()->status);
    }

    public function testUndeclaredObjectivesAreReportedAsSuch(): void
    {
        $inspector = $this->inspector(['0 2 * * *'], objectives: null);

        self::assertSame(ObjectiveStatus::Undeclared, $inspector->recoveryTime()->status);
        self::assertSame(ObjectiveStatus::Undeclared, $inspector->recoveryPoint()->status);
    }

    public function testRpoIsTheAgeOfUnarchivedWal(): void
    {
        $rpo = $this->inspector([], archiver: $this->archiver(lastArchivedSecondsAgo: 33, current: '0000000100000000000005E8', last: '0000000100000000000005E7'))
            ->recoveryPoint();

        self::assertSame(ObjectiveStatus::Met, $rpo->status);
        self::assertSame(33, $rpo->exposureSeconds);
    }

    public function testRpoIsBreachedWhenArchivingFallsBehind(): void
    {
        $rpo = $this->inspector([], archiver: $this->archiver(lastArchivedSecondsAgo: 301, current: '0000000100000000000005EB', last: '0000000100000000000005E7', failedSecondsAgo: 10))
            ->recoveryPoint();

        self::assertSame(ObjectiveStatus::Breached, $rpo->status);
        self::assertSame(301, $rpo->exposureSeconds);
        self::assertStringContainsString('archive_command is failing', $rpo->reason);
    }

    /** An idle cluster that has archived everything it wrote has lost nothing, however old the last segment is. */
    public function testNothingUnarchivedMeansZeroExposure(): void
    {
        $rpo = $this->inspector([], archiver: $this->archiver(lastArchivedSecondsAgo: 86_400, current: '0000000100000000000005E7', last: '0000000100000000000005E7'))
            ->recoveryPoint();

        self::assertSame(ObjectiveStatus::Met, $rpo->status);
        self::assertSame(0, $rpo->exposureSeconds);
    }

    public function testALaterTimelineIsUnarchivedWal(): void
    {
        $status = new ArchiverStatus('00000001000000000000FFFF', $this->now, null, null, '000000020000000100000000');

        self::assertTrue($status->hasUnarchivedWal());
    }

    public function testNeverArchivedIsBreached(): void
    {
        $archiver = $this->reader(new ArchiverStatus(null, null, null, null, '000000010000000000000001'));

        self::assertSame(ObjectiveStatus::Breached, $this->inspector([], archiver: $archiver)->recoveryPoint()->status);
    }

    public function testAStandbyCannotAnswer(): void
    {
        $archiver = $this->reader(new ArchiverStatus('000000010000000000000001', $this->now, null, null, null));

        self::assertSame(ObjectiveStatus::Indeterminate, $this->inspector([], archiver: $archiver)->recoveryPoint()->status);
    }

    public function testAnUnreachableClusterIsIndeterminateNotMet(): void
    {
        $archiver = new class implements ArchiverStatusReaderInterface {
            public function read(): ArchiverStatus
            {
                throw new RuntimeException('connection refused');
            }
        };

        self::assertSame(ObjectiveStatus::Indeterminate, $this->inspector([], archiver: $archiver)->recoveryPoint()->status);
    }

    private function seedProductionEvidence(): void
    {
        $this->seedBase('2026-09-13 02:00:04');
        $this->seedPitrDrill(segments: 828, recoveryMs: 333_660, rtoMs: 341_297);
    }

    private function seedBase(string $at): void
    {
        $createdAt = new DateTimeImmutable($at . ' UTC');

        $this->catalog->record(new BackupArtifact(
            id: BackupId::generate(DatabaseEngine::Postgres, BackupKind::PhysicalBase, $createdAt),
            engine: DatabaseEngine::Postgres,
            kind: BackupKind::PhysicalBase,
            environment: self::ENV,
            createdAt: $createdAt,
            sizeBytes: 210_000_000,
            checksum: BackupChecksum::sha256(str_repeat('a', 64)),
            storeKey: 'backups/base',
            codec: CompressionCodec::None,
            sourceRef: SourceRef::none(),
        ));
    }

    private function seedPitrDrill(int $segments, int $recoveryMs, int $rtoMs, DrillOutcome $outcome = DrillOutcome::Passed): void
    {
        $evidence = new PitrRecoveryOutcome($segments, '0/55C00028', '0/BD400000', '0000000100000000000005E9', true, $recoveryMs, '1');

        $this->drills->save(new DrillReport(
            'drill-20260913T153328',
            DatabaseEngine::Postgres,
            self::ENV,
            'base',
            new DateTimeImmutable('2026-09-13 15:33:28 UTC'),
            $rtoMs,
            $outcome,
            [InvariantResult::pass('row_count', '6 tables checked'), InvariantResult::pass('wal_replayed', $evidence->summary())],
            null,
            BackupKind::PhysicalBase,
        ));
    }

    /** @param list<string> $baseCrons */
    private function inspector(
        array $baseCrons,
        ?RecoveryObjectives $objectives = new RecoveryObjectives(300, 1800),
        ?ArchiverStatusReaderInterface $archiver = null,
    ): RecoveryObjectivesInspector {
        $schedules = array_map(
            static fn (string $cron, int $i): BackupSchedule => new BackupSchedule("base-{$i}", DatabaseEngine::Postgres, BackupKind::PhysicalBase, self::ENV, $cron, BackupScheduleType::Backup),
            $baseCrons,
            array_keys($baseCrons),
        );

        // A logical schedule too, so the inspector must pick the base schedule rather than any backup.
        $schedules[] = new BackupSchedule('dumps', DatabaseEngine::Postgres, BackupKind::LogicalFull, self::ENV, '0 */12 * * *', BackupScheduleType::Backup);

        return new RecoveryObjectivesInspector(
            $objectives,
            $archiver ?? $this->archiver(lastArchivedSecondsAgo: 30, current: '0000000100000000000005E8', last: '0000000100000000000005E7'),
            $this->catalog,
            $this->walAt60SegmentsPerHour(),
            $this->drills,
            new BackupScheduleRegistry($schedules),
            new FixedClock($this->now),
            self::ENV,
        );
    }

    /** Archived WAL at exactly one segment a minute, which is what archive_timeout=60 produced in production. */
    private function walAt60SegmentsPerHour(): WalVolumeReadModelInterface
    {
        $now = $this->now;

        return new class ($now) implements WalVolumeReadModelInterface {
            public function __construct(private readonly DateTimeImmutable $now) {}

            public function walVolumeSince(DatabaseEngine $engine, string $environment, DateTimeImmutable $from): array
            {
                $segments = intdiv(max(0, $this->now->getTimestamp() - $from->getTimestamp()), 60);

                return ['segments' => $segments, 'bytes' => $segments * 106_000];
            }

            public function newestWalSegmentName(DatabaseEngine $engine, string $environment): ?string
            {
                return null;
            }
        };
    }

    private function archiver(int $lastArchivedSecondsAgo, string $current, string $last, ?int $failedSecondsAgo = null): ArchiverStatusReaderInterface
    {
        return $this->reader(new ArchiverStatus(
            $last,
            $this->now->modify("-{$lastArchivedSecondsAgo} seconds"),
            $failedSecondsAgo !== null ? $current : null,
            $failedSecondsAgo !== null ? $this->now->modify("-{$failedSecondsAgo} seconds") : null,
            $current,
        ));
    }

    private function reader(ArchiverStatus $status): ArchiverStatusReaderInterface
    {
        return new class ($status) implements ArchiverStatusReaderInterface {
            public function __construct(private readonly ArchiverStatus $status) {}

            public function read(): ArchiverStatus
            {
                return $this->status;
            }
        };
    }
}
