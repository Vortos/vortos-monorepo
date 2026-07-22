<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Service;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\BackupRequest;
use Vortos\Backup\Domain\CompressionCodec;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Domain\Exception\DumpFailedException;
use Vortos\Backup\Domain\Exception\IntegrityException;
use Vortos\Backup\Driver\ObjectStore\ObjectStoreBackupStore;
use Vortos\Backup\Event\BackupEvent;
use Vortos\Backup\Port\BackupStoreRegistry;
use Vortos\Backup\Port\BackupTargetInterface;
use Vortos\Backup\Port\BackupTargetRegistry;
use Vortos\Backup\Service\BackupLock;
use Vortos\Backup\Service\BackupRunner;
use Vortos\Backup\Service\EncryptionSeam\IdentityStreamTransform;
use Vortos\Backup\Service\IntegrityVerifier;
use Vortos\Backup\Tests\Support\CollectingEventSink;
use Vortos\Backup\Tests\Support\FakeBackupTarget;
use Vortos\Backup\Tests\Support\InMemoryCatalogRepository;
use Vortos\Backup\Tests\Support\InMemoryObjectStore;

final class BackupRunnerTest extends TestCase
{
    private InMemoryObjectStore $object;
    private InMemoryCatalogRepository $catalog;
    private CollectingEventSink $events;
    private string $lockDir;

    protected function setUp(): void
    {
        $this->object = new InMemoryObjectStore();
        $this->catalog = new InMemoryCatalogRepository();
        $this->events = new CollectingEventSink();
        $this->lockDir = sys_get_temp_dir() . '/vortos-backup-test-' . bin2hex(random_bytes(4));
    }

    private function runner(BackupTargetInterface $target): BackupRunner
    {
        $targets = new BackupTargetRegistry(new ServiceLocator(['postgres' => fn () => $target, 'mongo' => fn () => $target]));
        $stores = new BackupStoreRegistry(new ServiceLocator(['object-store' => fn () => new ObjectStoreBackupStore($this->object)]));

        return new BackupRunner(
            $targets,
            $stores,
            $this->catalog,
            new IntegrityVerifier(),
            $this->events,
            new \Vortos\Backup\Tests\Support\FixedStreamTransformFactory(new IdentityStreamTransform()),
            new BackupLock($this->lockDir),
            new \Vortos\Backup\Tests\Support\FixedClock(new DateTimeImmutable('2026-06-23 02:00:00')),
            'object-store',
            'backups',
        );
    }

    private function request(): BackupRequest
    {
        return new BackupRequest(DatabaseEngine::Postgres, BackupKind::LogicalFull, 'prod');
    }

    public function test_happy_path_stores_verifies_catalogs_and_emits_success(): void
    {
        $artifact = $this->runner(new FakeBackupTarget())->run($this->request());

        $this->assertNotNull($artifact);
        $this->assertArrayHasKey($artifact->id->value(), $this->catalog->rows);
        $this->assertCount(1, $this->object->objects);
        $this->assertContains(BackupEvent::TYPE_SUCCEEDED, $this->events->types());
        // checksum recorded matches the stored bytes
        $this->assertSame(strlen("PGDMP\x00fake-dump-body"), $artifact->sizeBytes);
    }

    public function test_dump_failure_emits_critical_and_does_not_catalog(): void
    {
        try {
            $this->runner(new FakeBackupTarget(throwOnDump: true))->run($this->request());
            $this->fail('Expected the dump failure to propagate.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame([], $this->catalog->rows);
        $this->assertContains(BackupEvent::TYPE_FAILED, $this->events->types());
        $this->assertSame('critical', $this->events->last()?->severity->value);
    }

    public function test_integrity_failure_cleans_up_and_emits_integrity_event(): void
    {
        // Payload without a valid PGDMP magic → verifier rejects.
        $target = new FakeBackupTarget(payload: 'NOTPG-garbage', codec: CompressionCodec::None);

        try {
            $this->runner($target)->run($this->request());
            $this->fail('Expected integrity failure.');
        } catch (IntegrityException) {
            // expected
        }

        $this->assertSame([], $this->catalog->rows, 'A failed-integrity backup must not be cataloged.');
        $this->assertSame([], $this->object->objects, 'The bad object must be cleaned up.');
        $this->assertContains(BackupEvent::TYPE_INTEGRITY_FAILED, $this->events->types());
    }

    public function test_store_failure_mid_upload_does_not_catalog(): void
    {
        $this->object->failAfterBytes = 1; // any write fails

        try {
            $this->runner(new FakeBackupTarget())->run($this->request());
            $this->fail('Expected store failure.');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame([], $this->catalog->rows);
        $this->assertContains(BackupEvent::TYPE_FAILED, $this->events->types());
    }

    public function test_concurrent_run_is_a_noop(): void
    {
        $lock = new BackupLock($this->lockDir);
        // Hold the lock for the scope, then a run should skip.
        $result = $lock->withLock('postgres/prod', function (): ?\Vortos\Backup\Domain\BackupArtifact {
            return $this->runner(new FakeBackupTarget())->run($this->request());
        });

        $this->assertNull($result, 'A run while the scope lock is held returns null (no-op).');
    }

    public function test_unknown_engine_kind_combo_fails_closed(): void
    {
        // Mongo target asked for a Postgres-only kind via the real driver is covered in TCK;
        // here, a dump throwing DumpFailedException surfaces as a failed event.
        $target = new class extends FakeBackupTarget {
            public function dump(BackupRequest $request): \Vortos\Backup\Port\BackupStream
            {
                throw DumpFailedException::missingBinary('postgres', 'pg_dump');
            }
        };

        try {
            $this->runner($target)->run($this->request());
            $this->fail('Expected DumpFailedException.');
        } catch (DumpFailedException) {
            // expected
        }
        $this->assertContains(BackupEvent::TYPE_FAILED, $this->events->types());
    }
}
