<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Runtime;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Backup\Crypto\EnvelopeStreamCipher;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\BackupRequest;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Domain\RetentionPolicy;
use Vortos\Backup\Drill\DrillRunner;
use Vortos\Backup\Driver\ObjectStore\ObjectStoreBackupStore;
use Vortos\Backup\Port\BackupStoreRegistry;
use Vortos\Backup\Port\BackupTargetRegistry;
use Vortos\Backup\Restore\RestoreCoordinator;
use Vortos\Backup\Restore\RestoreTargetRegistry;
use Vortos\Backup\Runtime\BackupLifecycleRunner;
use Vortos\Backup\Schedule\BackupSchedule;
use Vortos\Backup\Schedule\BackupScheduleType;
use Vortos\Backup\Service\BackupLock;
use Vortos\Backup\Service\BackupRunner;
use Vortos\Backup\Service\EncryptionSeam\IdentityStreamTransformFactory;
use Vortos\Backup\Service\IntegrityVerifier;
use Vortos\Backup\Service\RetentionEnforcer;
use Vortos\Backup\Tests\Support\CollectingEventSink;
use Vortos\Backup\Tests\Support\FakeBackupTarget;
use Vortos\Backup\Tests\Support\FakeDrillProvisioner;
use Vortos\Backup\Tests\Support\FakeRestoreTarget;
use Vortos\Backup\Tests\Support\FixedClock;
use Vortos\Backup\Tests\Support\InMemoryCatalogRepository;
use Vortos\Backup\Tests\Support\InMemoryDrillReportStore;
use Vortos\Backup\Tests\Support\InMemoryObjectStore;

/**
 * The link between a declared drill schedule and the restore path it actually exercises.
 *
 * One argument, and the whole feature rests on it. Without the schedule's kind reaching the runner,
 * the runner falls back to "the newest restorable artifact" — which, on an installation taking
 * logical dumps every twelve hours and a base backup weekly, is whichever happens to be newer. The
 * daily logical drill and the weekly point-in-time drill would then drill the same artifact on two
 * different crons, each reporting green for a restore path neither had touched.
 *
 * Driven through a REAL DrillRunner rather than a double: the class is final by design, and the
 * assertion worth making is about the artifact that gets selected, which only the real selection
 * logic can answer.
 */
final class DrillScheduleKindDispatchTest extends TestCase
{
    private InMemoryObjectStore $objectStore;
    private InMemoryCatalogRepository $catalog;
    private CollectingEventSink $events;
    private InMemoryDrillReportStore $reports;

    protected function setUp(): void
    {
        $this->objectStore = new InMemoryObjectStore();
        $this->catalog = new InMemoryCatalogRepository();
        $this->events = new CollectingEventSink();
        $this->reports = new InMemoryDrillReportStore();
    }

    private function seed(BackupKind $kind, string $at): void
    {
        (new BackupRunner(
            new BackupTargetRegistry(new ServiceLocator(['postgres' => fn () => new FakeBackupTarget()])),
            $this->stores(),
            $this->catalog,
            new IntegrityVerifier(),
            $this->events,
            new IdentityStreamTransformFactory(),
            new BackupLock(sys_get_temp_dir() . '/drill-kind-' . bin2hex(random_bytes(4))),
            new FixedClock(new DateTimeImmutable($at)),
            'object-store',
            'backups',
        ))->run(new BackupRequest(DatabaseEngine::Postgres, $kind, 'production'));

        $this->events->events = [];
    }

    private function stores(): BackupStoreRegistry
    {
        return new BackupStoreRegistry(new ServiceLocator([
            'object-store' => fn () => new ObjectStoreBackupStore($this->objectStore),
        ]));
    }

    private function lifecycle(): BackupLifecycleRunner
    {
        $drillRunner = new DrillRunner(
            $this->catalog,
            $this->stores(),
            new RestoreCoordinator(
                new RestoreTargetRegistry(new ServiceLocator(['postgres' => fn () => new FakeRestoreTarget()])),
                new EnvelopeStreamCipher(),
                null,
            ),
            new FakeDrillProvisioner(),
            $this->reports,
            $this->events,
            new FixedClock(new DateTimeImmutable('2026-09-03 05:00:00')),
            [],
            'object-store',
            null,
            // No point-in-time provisioner: this installation cannot drill a base backup, which is
            // what makes the logical assertion below meaningful rather than incidental.
            null,
        );

        return new BackupLifecycleRunner(
            new BackupRunner(
                new BackupTargetRegistry(new ServiceLocator(['postgres' => fn () => new FakeBackupTarget()])),
                $this->stores(),
                $this->catalog,
                new IntegrityVerifier(),
                $this->events,
                new IdentityStreamTransformFactory(),
                new BackupLock(sys_get_temp_dir() . '/lifecycle-' . bin2hex(random_bytes(4))),
                new FixedClock(new DateTimeImmutable('2026-09-03 05:00:00')),
                'object-store',
                'backups',
            ),
            new RetentionEnforcer(
                $this->catalog,
                $this->catalog,
                $this->events,
                new FixedClock(new DateTimeImmutable('2026-09-03 05:00:00')),
            ),
            $this->stores(),
            new RetentionPolicy(),
            'object-store',
            $drillRunner,
        );
    }

    private function schedule(BackupKind $kind): BackupSchedule
    {
        return new BackupSchedule(
            'a-drill',
            DatabaseEngine::Postgres,
            $kind,
            'production',
            '0 5 * * 0',
            BackupScheduleType::Drill,
        );
    }

    /**
     * The base backup is NEWER, so an unqualified drill would take it. The schedule says logical, so
     * the logical dump is what gets restored.
     */
    public function testALogicalScheduleDrillsTheLogicalDumpEvenWhenABaseBackupIsNewer(): void
    {
        $this->seed(BackupKind::LogicalFull, '2026-09-03 02:00:00');
        $this->seed(BackupKind::PhysicalBase, '2026-09-03 04:00:00');

        $outcome = $this->lifecycle()->execute($this->schedule(BackupKind::LogicalFull));

        self::assertStringContainsString('logical_full', $outcome->summary);

        $report = $this->reports->latestOfKind('postgres', 'production', BackupKind::LogicalFull);
        self::assertNotNull($report, 'the drill must be recorded under the kind it proved');
        self::assertTrue($report->passed());
    }

    /**
     * And the schedule that asks for a base backup on an installation that cannot restore one must
     * fail loudly, rather than quietly drilling the dump and reporting green.
     */
    public function testAPointInTimeScheduleFailsRatherThanSilentlyDrillingADump(): void
    {
        $this->seed(BackupKind::LogicalFull, '2026-09-03 02:00:00');
        $this->seed(BackupKind::PhysicalBase, '2026-09-03 04:00:00');

        $this->expectExceptionMessageMatches('/cannot perform one/');

        $this->lifecycle()->execute($this->schedule(BackupKind::PhysicalBase));
    }
}
