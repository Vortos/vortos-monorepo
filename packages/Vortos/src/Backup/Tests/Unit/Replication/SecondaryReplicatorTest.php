<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Replication;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Backup\Domain\BackupChecksum;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\CompressionCodec;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Domain\SourceRef;
use Vortos\Backup\Driver\ObjectStore\ObjectStoreBackupStore;
use Vortos\Backup\Event\BackupEvent;
use Vortos\Backup\Port\BackupStream;
use Vortos\Backup\Replication\SecondaryReplicator;
use Vortos\Backup\Tests\Support\ArtifactFactory;
use Vortos\Backup\Tests\Support\CollectingEventSink;
use Vortos\Backup\Tests\Support\FixedClock;
use Vortos\Backup\Tests\Support\InMemoryObjectStore;

final class SecondaryReplicatorTest extends TestCase
{
    public function test_replicate_copies_to_secondary_and_verifies_checksum(): void
    {
        $primaryStore = new InMemoryObjectStore();
        $secondaryStore = new InMemoryObjectStore();
        $events = new CollectingEventSink();
        $clock = new FixedClock(new DateTimeImmutable('2026-06-24'));

        $data = 'test-backup-data';
        $primaryStore->objects['backups/prod/postgres/logical_full/test'] = $data;

        $artifact = ArtifactFactory::at('2026-06-24 02:00:00');
        // We need an artifact with the right store key and checksum
        $artifact = new \Vortos\Backup\Domain\BackupArtifact(
            $artifact->id,
            $artifact->engine,
            $artifact->kind,
            $artifact->environment,
            $artifact->createdAt,
            strlen($data),
            BackupChecksum::ofString($data),
            'backups/prod/postgres/logical_full/test',
            CompressionCodec::None,
            SourceRef::none(),
        );

        $primary = new ObjectStoreBackupStore($primaryStore);
        $secondary = new ObjectStoreBackupStore($secondaryStore);

        $replicator = new SecondaryReplicator($secondary, $events, $clock);
        $result = $replicator->replicate($artifact, $primary);

        $this->assertTrue($result->success);
        $this->assertNotNull($result->secondaryKey);
        $this->assertArrayHasKey($result->secondaryKey, $secondaryStore->objects);
        $this->assertSame($data, $secondaryStore->objects[$result->secondaryKey]);
    }

    public function test_absent_secondary_returns_skipped(): void
    {
        $events = new CollectingEventSink();
        $clock = new FixedClock(new DateTimeImmutable('2026-06-24'));

        $replicator = new SecondaryReplicator(null, $events, $clock);
        $result = $replicator->replicate(ArtifactFactory::at('2026-06-24 02:00:00'), new ObjectStoreBackupStore(new InMemoryObjectStore()));

        $this->assertTrue($result->success);
        $this->assertNull($result->secondaryKey);
    }

    public function test_replication_failure_emits_critical_without_voiding_copy_one(): void
    {
        $primaryStore = new InMemoryObjectStore();
        $secondaryStore = new InMemoryObjectStore();
        $secondaryStore->failAfterBytes = 1;
        $events = new CollectingEventSink();
        $clock = new FixedClock(new DateTimeImmutable('2026-06-24'));

        $data = 'test-backup-data';
        $primaryStore->objects['backups/prod/postgres/logical_full/test'] = $data;

        $artifact = new \Vortos\Backup\Domain\BackupArtifact(
            \Vortos\Backup\Domain\BackupId::generate(DatabaseEngine::Postgres, BackupKind::LogicalFull, new DateTimeImmutable('2026-06-24')),
            DatabaseEngine::Postgres,
            BackupKind::LogicalFull,
            'prod',
            new DateTimeImmutable('2026-06-24'),
            strlen($data),
            BackupChecksum::ofString($data),
            'backups/prod/postgres/logical_full/test',
            CompressionCodec::None,
            SourceRef::none(),
        );

        $primary = new ObjectStoreBackupStore($primaryStore);
        $secondary = new ObjectStoreBackupStore($secondaryStore);

        $replicator = new SecondaryReplicator($secondary, $events, $clock);
        $result = $replicator->replicate($artifact, $primary);

        $this->assertFalse($result->success);
        $this->assertContains(BackupEvent::TYPE_REPLICATION_FAILED, $events->types());
        $this->assertSame($data, $primaryStore->objects['backups/prod/postgres/logical_full/test'], 'Copy #1 must remain intact.');
    }
}
