<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Drill;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Backup\Crypto\EnvelopeStreamCipher;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\BackupRequest;
use Vortos\Backup\Domain\CompressionCodec;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Drill\DrillEnvironment;
use Vortos\Backup\Drill\DrillEnvironmentProvisionerInterface;
use Vortos\Backup\Drill\DrillRunner;
use Vortos\Backup\Driver\ObjectStore\ObjectStoreBackupStore;
use Vortos\Backup\Pitr\PitrRecoveryOutcome;
use Vortos\Backup\Pitr\PitrRecoveryRecorder;
use Vortos\Backup\Port\BackupStoreRegistry;
use Vortos\Backup\Port\BackupTargetRegistry;
use Vortos\Backup\Restore\Capability\RestoreTargetCapability;
use Vortos\Backup\Restore\Driver\Postgres\PostgresPitrRestoreTarget;
use Vortos\Backup\Restore\RestoreCoordinator;
use Vortos\Backup\Restore\RestoreRequest;
use Vortos\Backup\Restore\RestoreTargetInterface;
use Vortos\Backup\Restore\RestoreTargetRegistry;
use Vortos\Backup\Service\BackupLock;
use Vortos\Backup\Service\BackupRunner;
use Vortos\Backup\Service\EncryptionSeam\IdentityStreamTransformFactory;
use Vortos\Backup\Service\IntegrityVerifier;
use Vortos\Backup\Tests\Support\CollectingEventSink;
use Vortos\Backup\Tests\Support\FakeBackupTarget;
use Vortos\Backup\Tests\Support\FakeDrillProvisioner;
use Vortos\Backup\Tests\Support\FakeKeyProvider;
use Vortos\Backup\Tests\Support\FakeRestoreTarget;
use Vortos\Backup\Tests\Support\FixedClock;
use Vortos\Backup\Tests\Support\InMemoryCatalogRepository;
use Vortos\Backup\Tests\Support\InMemoryDrillReportStore;
use Vortos\Backup\Tests\Support\InMemoryObjectStore;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * Which artifact a drill selects, and what it refuses to do.
 *
 * The most dangerous failure a restore drill has is not falling over — it is quietly proving
 * something weaker than it claims. These cover the three ways that can happen once there are two
 * restore paths: picking the wrong artifact, silently downgrading an explicit point-in-time request
 * to a logical one, and reporting a pass for a recovery that replayed nothing.
 */
final class DrillRunnerPitrTest extends TestCase
{
    private InMemoryObjectStore $objectStore;
    private InMemoryCatalogRepository $catalog;
    private CollectingEventSink $events;
    private FakeKeyProvider $keyProvider;
    private InMemoryDrillReportStore $reportStore;

    protected function setUp(): void
    {
        $this->objectStore = new InMemoryObjectStore();
        $this->catalog = new InMemoryCatalogRepository();
        $this->events = new CollectingEventSink();
        $this->keyProvider = new FakeKeyProvider();
        $this->reportStore = new InMemoryDrillReportStore();
    }

    /**
     * Artifacts are seeded UNENCRYPTED here on purpose.
     *
     * These tests are about which artifact a drill selects and what it refuses to do; the envelope
     * seam has its own coverage. Going through the encrypting transform would additionally make the
     * whole file depend on ext-sodium, which is exactly the reason the existing drill suite cannot
     * run outside CI — a selection bug would then be invisible on the machine it was written on.
     */
    private function seed(BackupKind $kind, string $at): void
    {
        $store = new ObjectStoreBackupStore($this->objectStore);

        (new BackupRunner(
            new BackupTargetRegistry(new ServiceLocator(['postgres' => fn () => new FakeBackupTarget()])),
            new BackupStoreRegistry(new ServiceLocator(['object-store' => fn () => $store])),
            $this->catalog,
            new IntegrityVerifier(),
            $this->events,
            new IdentityStreamTransformFactory(),
            new BackupLock(sys_get_temp_dir() . '/pitr-drill-' . bin2hex(random_bytes(4))),
            new FixedClock(new DateTimeImmutable($at)),
            'object-store',
            'backups',
        ))->run(new BackupRequest(DatabaseEngine::Postgres, $kind, 'prod'));

        $this->events->events = [];
    }

    /** A target that records the requested recovery, standing in for a real container recovery. */
    private function pitrTarget(?PitrRecoveryOutcome $outcome): RestoreTargetInterface
    {
        return new class ($outcome) implements RestoreTargetInterface {
            public ?RestoreRequest $seen = null;

            public function __construct(private readonly ?PitrRecoveryOutcome $outcome) {}

            public function capabilities(): CapabilityDescriptor
            {
                return CapabilityDescriptor::create([
                    RestoreTargetCapability::StreamingRestore->value => true,
                    RestoreTargetCapability::CleanRestore->value => false,
                    RestoreTargetCapability::PointInTime->value => true,
                ]);
            }

            public function engine(): DatabaseEngine
            {
                return DatabaseEngine::Postgres;
            }

            public function restore(iterable $chunks, RestoreRequest $request): void
            {
                $this->seen = $request;
                foreach ($chunks as $ignored) {
                    // drain
                }

                $recorder = $request->options[PostgresPitrRestoreTarget::OPTION_RECORDER] ?? null;
                if ($recorder instanceof PitrRecoveryRecorder && $this->outcome !== null) {
                    $recorder->record($this->outcome);
                }
            }
        };
    }

    private function pitrProvisioner(): DrillEnvironmentProvisionerInterface
    {
        return new class implements DrillEnvironmentProvisionerInterface {
            public bool $tornDown = false;

            public function capabilities(): CapabilityDescriptor
            {
                return CapabilityDescriptor::create(['point_in_time' => true]);
            }

            public function provision(DatabaseEngine $engine): DrillEnvironment
            {
                return new DrillEnvironment(
                    'pgsql://sqoura:pw@vortos-pitr-abc:5432/app',
                    'container-abc',
                    [
                        PostgresPitrRestoreTarget::OPTION_CONTAINER_ID => 'container-abc',
                        PostgresPitrRestoreTarget::OPTION_CONTAINER_NAME => 'vortos-pitr-abc',
                        PostgresPitrRestoreTarget::OPTION_PGDATA => '/var/lib/postgresql/18/docker',
                    ],
                );
            }

            public function teardown(DrillEnvironment $env): void
            {
                $this->tornDown = true;
            }
        };
    }

    private function runner(
        ?RestoreTargetInterface $pitrTarget,
        ?DrillEnvironmentProvisionerInterface $pitrProvisioner,
    ): DrillRunner {
        $targets = ['postgres' => fn () => new FakeRestoreTarget()];
        if ($pitrTarget !== null) {
            $targets['postgres-pitr'] = fn () => $pitrTarget;
        }

        return new DrillRunner(
            $this->catalog,
            new BackupStoreRegistry(new ServiceLocator([
                'object-store' => fn () => new ObjectStoreBackupStore($this->objectStore),
            ])),
            new RestoreCoordinator(
                new RestoreTargetRegistry(new ServiceLocator($targets)),
                new EnvelopeStreamCipher(),
                null,
            ),
            new FakeDrillProvisioner(),
            $this->reportStore,
            $this->events,
            new FixedClock(new DateTimeImmutable('2026-09-03 05:00:00')),
            [],
            'object-store',
            null,
            $pitrProvisioner,
        );
    }

    /**
     * The core of the feature: --pitr must drill the base backup even though a logical dump is
     * newer. Left to pick for itself the runner always takes the more recent artifact, and since
     * dumps are taken far more often than bases, the point-in-time path would never be exercised.
     */
    public function testAPointInTimeDrillSelectsTheBaseBackupEvenWhenALogicalDumpIsNewer(): void
    {
        $this->seed(BackupKind::PhysicalBase, '2026-09-03 02:00:00');
        $this->seed(BackupKind::LogicalFull, '2026-09-03 04:00:00');

        $target = $this->pitrTarget(
            new PitrRecoveryOutcome(190, '0/3000028', '0/5000100', '0000000100000000000000BE', true, 94000, '1'),
        );

        $report = $this->runner($target, $this->pitrProvisioner())
            ->run(DatabaseEngine::Postgres, 'prod', false, BackupKind::PhysicalBase);

        self::assertTrue($report->passed(), $report->error ?? '');
        self::assertSame(BackupKind::PhysicalBase, $report->kind);

        $replayed = array_values(array_filter(
            $report->invariants,
            static fn ($r): bool => $r->name === 'wal_replayed',
        ));
        self::assertNotEmpty($replayed, 'a point-in-time drill must assert that WAL was replayed');
        self::assertTrue($replayed[0]->passed);
        self::assertStringContainsString('190 WAL segments replayed', $replayed[0]->detail);
    }

    /**
     * A base backup that started up having replayed nothing is a restore to the base's own instant.
     * Every other invariant passes on it, so the drill must fail on the evidence alone.
     */
    public function testAPointInTimeDrillFailsWhenNoWalWasActuallyReplayed(): void
    {
        $this->seed(BackupKind::PhysicalBase, '2026-09-03 02:00:00');

        $target = $this->pitrTarget(new PitrRecoveryOutcome(0, '0/3000028', '0/3000028', null, false, 4000, '1'));

        $report = $this->runner($target, $this->pitrProvisioner())
            ->run(DatabaseEngine::Postgres, 'prod', false, BackupKind::PhysicalBase);

        self::assertFalse($report->passed());
    }

    /**
     * Refusal, not a silent downgrade. A schedule named for a point-in-time drill must never report
     * green having quietly restored a logical dump instead.
     */
    public function testRefusesAPointInTimeDrillWhenNoPitrTargetIsRegistered(): void
    {
        $this->seed(BackupKind::PhysicalBase, '2026-09-03 02:00:00');

        $this->expectExceptionMessageMatches('/cannot perform one/');

        $this->runner(null, $this->pitrProvisioner())
            ->run(DatabaseEngine::Postgres, 'prod', false, BackupKind::PhysicalBase);
    }

    /**
     * Both halves are required. A target advertising the capability without a provisioner able to
     * stand up a cluster would pass the gate and then fail at provision time — after the drill had
     * already discarded the logical dump it could have proved something with.
     */
    public function testRefusesAPointInTimeDrillWhenNoPitrProvisionerIsConfigured(): void
    {
        $this->seed(BackupKind::PhysicalBase, '2026-09-03 02:00:00');

        $this->expectExceptionMessageMatches('/cannot perform one/');

        $this->runner($this->pitrTarget(null), null)
            ->run(DatabaseEngine::Postgres, 'prod', false, BackupKind::PhysicalBase);
    }

    /**
     * Without point-in-time support the base backup must not even be a candidate — the logical
     * target honestly cannot consume it, and the failure would say nothing about the backup.
     */
    public function testAnUnqualifiedDrillIgnoresBaseBackupsWhenPointInTimeIsUnavailable(): void
    {
        $this->seed(BackupKind::LogicalFull, '2026-09-03 02:00:00');
        $this->seed(BackupKind::PhysicalBase, '2026-09-03 04:00:00');

        $report = $this->runner(null, null)->run(DatabaseEngine::Postgres, 'prod');

        self::assertSame(BackupKind::LogicalFull, $report->kind);
    }

    /**
     * A logical drill must not gain the point-in-time invariant, which would fail it for the
     * entirely correct reason that a dump replays no WAL.
     */
    public function testALogicalDrillDoesNotAssertWalReplay(): void
    {
        $this->seed(BackupKind::LogicalFull, '2026-09-03 02:00:00');

        $report = $this->runner($this->pitrTarget(null), $this->pitrProvisioner())
            ->run(DatabaseEngine::Postgres, 'prod', false, BackupKind::LogicalFull);

        self::assertTrue($report->passed(), $report->error ?? '');
        self::assertSame([], array_values(array_filter(
            $report->invariants,
            static fn ($r): bool => $r->name === 'wal_replayed',
        )));
    }

    /**
     * The container identity the provisioner produced must reach the target: without it the restore
     * has nowhere to put the data directory.
     */
    public function testTheProvisionersContainerHandleReachesTheRestoreTarget(): void
    {
        $this->seed(BackupKind::PhysicalBase, '2026-09-03 02:00:00');

        $target = $this->pitrTarget(
            new PitrRecoveryOutcome(5, '0/3000028', '0/4000000', '0000000100000000000000AA', true, 9000, '1'),
        );

        $this->runner($target, $this->pitrProvisioner())
            ->run(DatabaseEngine::Postgres, 'prod', false, BackupKind::PhysicalBase);

        self::assertSame(
            'container-abc',
            $target->seen?->options[PostgresPitrRestoreTarget::OPTION_CONTAINER_ID] ?? null,
        );
        self::assertInstanceOf(
            PitrRecoveryRecorder::class,
            $target->seen?->options[PostgresPitrRestoreTarget::OPTION_RECORDER] ?? null,
        );
    }
}
