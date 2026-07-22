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
use Vortos\Backup\Drill\DrillRunner;
use Vortos\Backup\Drill\InvariantCheck;
use Vortos\Backup\Drill\InvariantResult;
use Vortos\Backup\Driver\ObjectStore\ObjectStoreBackupStore;
use Vortos\Backup\Event\BackupEvent;
use Vortos\Backup\Port\BackupStoreRegistry;
use Vortos\Backup\Port\BackupTargetRegistry;
use Vortos\Backup\Restore\RestoreCoordinator;
use Vortos\Backup\Restore\RestoreTargetRegistry;
use Vortos\Backup\Service\BackupLock;
use Vortos\Backup\Service\BackupRunner;
use Vortos\Backup\Service\EncryptionSeam\EnvelopeStreamTransform;
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

final class DrillRunnerTest extends TestCase
{
    private InMemoryObjectStore $objectStore;
    private InMemoryCatalogRepository $catalog;
    private CollectingEventSink $events;
    private FakeKeyProvider $keyProvider;
    private FakeDrillProvisioner $provisioner;
    private InMemoryDrillReportStore $reportStore;
    private FakeRestoreTarget $restoreTarget;

    protected function setUp(): void
    {
        $this->objectStore = new InMemoryObjectStore();
        $this->catalog = new InMemoryCatalogRepository();
        $this->events = new CollectingEventSink();
        $this->keyProvider = new FakeKeyProvider();
        $this->provisioner = new FakeDrillProvisioner();
        $this->reportStore = new InMemoryDrillReportStore();
        $this->restoreTarget = new FakeRestoreTarget();
    }

    private function seedBackup(): void
    {
        $target = new FakeBackupTarget();
        $transform = new EnvelopeStreamTransform(
            $this->keyProvider,
            new EnvelopeStreamCipher(),
            DatabaseEngine::Postgres,
            BackupKind::LogicalFull,
            CompressionCodec::None,
        );

        $targets = new BackupTargetRegistry(new ServiceLocator(['postgres' => fn () => $target]));
        $store = new ObjectStoreBackupStore($this->objectStore);
        $stores = new BackupStoreRegistry(new ServiceLocator(['object-store' => fn () => $store]));

        $runner = new BackupRunner(
            $targets, $stores, $this->catalog, new IntegrityVerifier(), $this->events, new \Vortos\Backup\Tests\Support\FixedStreamTransformFactory($transform),
            new BackupLock(sys_get_temp_dir() . '/drill-test-' . bin2hex(random_bytes(4))),
            new FixedClock(new DateTimeImmutable('2026-06-24 02:00:00')),
            'object-store', 'backups',
        );

        $runner->run(new BackupRequest(DatabaseEngine::Postgres, BackupKind::LogicalFull, 'prod'));
        $this->events->events = [];
    }

    private function drillRunner(array $checks = [], bool $withKeyProvider = true): DrillRunner
    {
        $store = new ObjectStoreBackupStore($this->objectStore);
        $stores = new BackupStoreRegistry(new ServiceLocator(['object-store' => fn () => $store]));
        $restoreTargets = new RestoreTargetRegistry(new ServiceLocator(['postgres' => fn () => $this->restoreTarget]));

        $coordinator = new RestoreCoordinator($restoreTargets, new EnvelopeStreamCipher(), $this->keyProvider);

        return new DrillRunner(
            $this->catalog, $stores, $coordinator, $this->provisioner, $this->reportStore,
            $this->events, new FixedClock(new DateTimeImmutable('2026-06-24 03:00:00')),
            $checks, 'object-store',
            $withKeyProvider ? $this->keyProvider : null,
        );
    }

    public function test_drill_happy_path_restores_and_emits_success(): void
    {
        $this->seedBackup();
        $passingCheck = new class implements InvariantCheck {
            public function name(): string { return 'test_check'; }
            public function check(array $connectionParams): InvariantResult {
                return InvariantResult::pass('test_check', 'ok');
            }
        };

        $report = $this->drillRunner([$passingCheck])->run(DatabaseEngine::Postgres, 'prod');

        $this->assertTrue($report->passed());
        $this->assertGreaterThanOrEqual(0, $report->rtoMs);
        $this->assertNotNull($this->restoreTarget->restoredData);
        $this->assertSame("PGDMP\x00fake-dump-body", $this->restoreTarget->restoredData);
        $this->assertContains(BackupEvent::TYPE_DRILL_SUCCEEDED, $this->events->types());
    }

    public function test_drill_teardown_runs_even_on_invariant_failure(): void
    {
        $this->seedBackup();
        $failingCheck = new class implements InvariantCheck {
            public function name(): string { return 'bad_check'; }
            public function check(array $connectionParams): InvariantResult {
                return InvariantResult::fail('bad_check', 'data missing');
            }
        };

        $report = $this->drillRunner([$failingCheck])->run(DatabaseEngine::Postgres, 'prod');

        $this->assertFalse($report->passed());
        $this->assertTrue($this->provisioner->tornDown, 'Teardown must run even when invariant fails.');
        $this->assertContains(BackupEvent::TYPE_DRILL_FAILED, $this->events->types());
    }

    public function test_drill_teardown_runs_on_exception(): void
    {
        $this->seedBackup();
        $this->restoreTarget->throwOnRestore = true;

        $report = $this->drillRunner()->run(DatabaseEngine::Postgres, 'prod');

        $this->assertFalse($report->passed());
        $this->assertTrue($this->provisioner->tornDown, 'Teardown must run even on restore exception.');
        $this->assertContains(BackupEvent::TYPE_DRILL_FAILED, $this->events->types());
    }

    public function test_drill_report_is_persisted(): void
    {
        $this->seedBackup();
        $this->drillRunner()->run(DatabaseEngine::Postgres, 'prod');

        $this->assertCount(1, $this->reportStore->reports);
        $saved = $this->reportStore->reports[0];
        $this->assertSame('prod', $saved->environment);
        $this->assertSame(DatabaseEngine::Postgres, $saved->engine);
    }

    public function test_no_backup_artifact_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No backup artifact/');

        $this->drillRunner()->run(DatabaseEngine::Postgres, 'prod');
    }

    public function test_drill_records_rto(): void
    {
        $this->seedBackup();
        $report = $this->drillRunner()->run(DatabaseEngine::Postgres, 'prod');

        $this->assertGreaterThanOrEqual(0, $report->rtoMs);
        $latest = $this->reportStore->latest('postgres', 'prod');
        $this->assertNotNull($latest);
        $this->assertSame($report->rtoMs, $latest->rtoMs);
    }

    public function test_shallow_drill_verifies_encrypted_artifact_with_key_provider(): void
    {
        $this->seedBackup();

        $report = $this->drillRunner()->run(DatabaseEngine::Postgres, 'prod', shallow: true);

        $this->assertTrue($report->passed());
        $this->assertContains(BackupEvent::TYPE_DRILL_SUCCEEDED, $this->events->types());
        $this->assertNull($this->restoreTarget->restoredData, 'Shallow drill must never provision or restore.');
    }

    public function test_shallow_drill_fails_closed_without_a_key_provider(): void
    {
        $this->seedBackup();

        $report = $this->drillRunner(withKeyProvider: false)->run(DatabaseEngine::Postgres, 'prod', shallow: true);

        $this->assertFalse($report->passed());
        $this->assertStringContainsString('no key provider configured', (string) $report->error);
        $this->assertContains(BackupEvent::TYPE_DRILL_FAILED, $this->events->types());
    }

    public function test_shallow_drill_detects_tampered_ciphertext(): void
    {
        $this->seedBackup();

        $latest = $this->catalog->latest(DatabaseEngine::Postgres, 'prod');
        $this->assertNotNull($latest);

        $corrupted = $this->objectStore->objects[$latest->storeKey];
        // Flip a byte well past the header so the AEAD auth tag check fails on decrypt.
        $offset = (int) (strlen($corrupted) / 2);
        $corrupted[$offset] = chr(~ord($corrupted[$offset]) & 0xFF);
        $this->objectStore->objects[$latest->storeKey] = $corrupted;

        $report = $this->drillRunner()->run(DatabaseEngine::Postgres, 'prod', shallow: true);

        $this->assertFalse($report->passed());
        $this->assertContains(BackupEvent::TYPE_DRILL_FAILED, $this->events->types());
    }
}
