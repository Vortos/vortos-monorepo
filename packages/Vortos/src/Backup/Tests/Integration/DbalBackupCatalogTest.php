<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Vortos\Backup\Catalog\BackupAlreadyExistsException;
use Vortos\Backup\Catalog\DbalBackupCatalogReadModel;
use Vortos\Backup\Catalog\DbalBackupCatalogRepository;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Tests\Support\ArtifactFactory;

final class DbalBackupCatalogTest extends TestCase
{
    private Connection $connection;
    private DbalBackupCatalogRepository $repo;
    private DbalBackupCatalogReadModel $readModel;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createTable();
        $this->repo = new DbalBackupCatalogRepository($this->connection, 'backup_catalog');
        $this->readModel = new DbalBackupCatalogReadModel($this->connection, 'backup_catalog');
    }

    public function test_record_and_read_back(): void
    {
        $artifact = ArtifactFactory::at('2026-06-23 02:00:00');
        $this->repo->record($artifact);

        $loaded = $this->readModel->byId($artifact->id->value());
        $this->assertNotNull($loaded);
        $this->assertSame($artifact->storeKey, $loaded->storeKey);
        $this->assertTrue($artifact->checksum->equals($loaded->checksum));
    }

    public function test_duplicate_id_is_rejected(): void
    {
        $artifact = ArtifactFactory::at('2026-06-23 02:00:00');
        $this->repo->record($artifact);

        $this->expectException(BackupAlreadyExistsException::class);
        $this->repo->record($artifact);
    }

    public function test_list_is_newest_first_and_filtered(): void
    {
        $this->repo->record(ArtifactFactory::at('2026-06-21 02:00:00'));
        $this->repo->record(ArtifactFactory::at('2026-06-23 02:00:00'));
        $this->repo->record(ArtifactFactory::at('2026-06-22 02:00:00'));
        $this->repo->record(ArtifactFactory::at('2026-06-20 02:00:00', BackupKind::MongoArchive, DatabaseEngine::Mongo));

        $pg = $this->readModel->list(DatabaseEngine::Postgres, 'prod');
        $this->assertCount(3, $pg);
        $this->assertSame('2026-06-23 02:00:00', $pg[0]->createdAt->format('Y-m-d H:i:s'));

        $mongo = $this->readModel->list(DatabaseEngine::Mongo, 'prod');
        $this->assertCount(1, $mongo);
    }

    public function test_latest(): void
    {
        $this->repo->record(ArtifactFactory::at('2026-06-21 02:00:00'));
        $newest = ArtifactFactory::at('2026-06-23 02:00:00');
        $this->repo->record($newest);

        $this->assertSame($newest->id->value(), $this->readModel->latest(DatabaseEngine::Postgres, 'prod')?->id->value());
    }

    public function test_forget_removes_row(): void
    {
        $artifact = ArtifactFactory::at('2026-06-23 02:00:00');
        $this->repo->record($artifact);
        $this->repo->forget($artifact->id->value());

        $this->assertNull($this->readModel->byId($artifact->id->value()));
    }

    public function test_list_restore_points_excludes_wal(): void
    {
        $this->repo->record(ArtifactFactory::at('2026-06-23 02:00:00', BackupKind::PhysicalBase));
        $this->repo->record(ArtifactFactory::at('2026-06-22 02:00:00', BackupKind::LogicalFull));
        $this->repo->record(ArtifactFactory::at('2026-06-23 03:00:00', BackupKind::WalSegment));

        $points = $this->readModel->listRestorePoints(DatabaseEngine::Postgres, 'prod');

        $this->assertCount(2, $points);
        foreach ($points as $point) {
            $this->assertTrue($point->isRestorePoint());
        }
        $this->assertSame('2026-06-23 02:00:00', $points[0]->createdAt->format('Y-m-d H:i:s'), 'Newest first.');
    }

    /**
     * The boundary that decides what gets deleted, tested from both sides in one place.
     *
     * A segment created at the same instant as the oldest retained base backup is still needed to
     * replay from it, so the cut is strict. Off by one here is off by one backup, permanently.
     */
    public function test_wal_boundary_is_strict_and_survives_the_round_trip(): void
    {
        $boundary = new \DateTimeImmutable('2026-06-23 02:00:00');

        $this->repo->record(ArtifactFactory::at('2026-06-23 01:59:59', BackupKind::WalSegment));
        $this->repo->record(ArtifactFactory::at('2026-06-23 02:00:00', BackupKind::WalSegment));
        $this->repo->record(ArtifactFactory::at('2026-06-23 02:00:01', BackupKind::WalSegment));
        $this->repo->record(ArtifactFactory::at('2026-06-23 02:00:00', BackupKind::PhysicalBase));

        $older = iterator_to_array($this->readModel->iterateWalOlderThan(DatabaseEngine::Postgres, 'prod', $boundary), false);

        $this->assertCount(1, $older, 'Only the segment strictly before the boundary is prunable.');
        $this->assertSame('2026-06-23 01:59:59', $older[0]->createdAt->format('Y-m-d H:i:s'));
        $this->assertSame(1, $this->readModel->countWalOlderThan(DatabaseEngine::Postgres, 'prod', $boundary), 'The prune count must agree with the stream.');

        // The two halves must account for every segment and overlap nowhere: whatever the driver
        // does to a datetime on the way in and out, the split stays a partition.
        $this->assertSame(2, $this->readModel->countWalFrom(DatabaseEngine::Postgres, 'prod', $boundary));
        $this->assertSame(3, $this->readModel->countWalFrom(DatabaseEngine::Postgres, 'prod', null));
    }

    /**
     * The retention boundary must not depend on the host's date.timezone.
     *
     * Scoped to what this suite can actually observe: SQLite stores and returns the ATOM string as
     * written, offset included, so this covers the encode side and the query comparison. It does
     * NOT exercise the driver shape production runs on — Postgres drops the offset and returns a
     * naive timestamp, and a naive string is the one that can be read in the wrong timezone. That
     * case is pinned in {@see \Vortos\Backup\Tests\Unit\Domain\BackupArtifactTimestampTest}, which
     * works on the string form directly rather than through a driver that cannot produce it.
     */
    public function test_wal_boundary_is_independent_of_the_host_timezone(): void
    {
        $original = date_default_timezone_get();
        date_default_timezone_set('Asia/Colombo'); // +05:30, and never a whole number of hours

        try {
            // Instants pinned with an explicit offset: the factory would otherwise read a bare
            // string as local time, which is the ambiguity under test rather than a fixture detail.
            $this->repo->record(ArtifactFactory::at('2026-06-23T01:00:00+00:00', BackupKind::WalSegment));
            $this->repo->record(ArtifactFactory::at('2026-06-23T03:00:00+00:00', BackupKind::WalSegment));

            $boundary = new \DateTimeImmutable('2026-06-23 02:00:00', new \DateTimeZone('UTC'));
            $older = iterator_to_array($this->readModel->iterateWalOlderThan(DatabaseEngine::Postgres, 'prod', $boundary), false);

            $this->assertCount(1, $older);
            $this->assertSame('2026-06-23 01:00:00', $older[0]->createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
            $this->assertSame(1, $this->readModel->countWalFrom(DatabaseEngine::Postgres, 'prod', $boundary));
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function test_wal_queries_are_scoped_to_engine_and_environment(): void
    {
        $boundary = new \DateTimeImmutable('2026-06-23 02:00:00');

        $this->repo->record(ArtifactFactory::at('2026-06-21 02:00:00', BackupKind::WalSegment));
        $this->repo->record(ArtifactFactory::at('2026-06-21 02:00:00', BackupKind::WalSegment, DatabaseEngine::Mongo));
        $this->repo->record(ArtifactFactory::at('2026-06-21 02:00:00', BackupKind::WalSegment, DatabaseEngine::Postgres, 'staging'));

        $this->assertCount(1, iterator_to_array($this->readModel->iterateWalOlderThan(DatabaseEngine::Postgres, 'prod', $boundary), false));
        $this->assertSame(1, $this->readModel->countWalOlderThan(DatabaseEngine::Postgres, 'prod', $boundary), 'Prune count is engine/env-scoped too.');
        $this->assertSame(0, $this->readModel->countWalFrom(DatabaseEngine::Postgres, 'prod', $boundary));
        $this->assertSame(1, $this->readModel->countWalFrom(DatabaseEngine::Postgres, 'staging', null));
    }


    /**
     * store_id must survive the round trip through the real repository.
     *
     * This is the seam that was missed. `record()` names every column explicitly, so a field added to
     * BackupArtifact::toArray() is dropped unless it is also named here — and the in-memory double
     * stores artifacts whole, so no unit test could see it. In production the result was WAL objects
     * written to their own bucket while their catalog rows claimed the primary one: retention would
     * then resolve them to the wrong store, where deleting an absent key is a no-op that reports
     * success, forgetting the row and orphaning the object.
     */
    public function test_store_id_is_persisted_and_read_back(): void
    {
        $artifact = ArtifactFactory::at('2026-08-08 16:48:02', BackupKind::WalSegment, storeId: 'object-store-wal');
        $this->repo->record($artifact);

        $loaded = $this->readModel->byId($artifact->id->value());

        $this->assertNotNull($loaded);
        $this->assertSame('object-store-wal', $loaded->storeId, 'The catalog must remember which bucket holds the bytes.');
    }

    /** An artifact with no explicit store still reads back as null, meaning the primary store. */
    public function test_an_unstamped_artifact_round_trips_as_null(): void
    {
        $artifact = ArtifactFactory::at('2026-08-08 02:00:00', BackupKind::PhysicalBase);
        $this->repo->record($artifact);

        $loaded = $this->readModel->byId($artifact->id->value());

        $this->assertNotNull($loaded);
        $this->assertNull($loaded->storeId);
    }

    private function createTable(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE backup_catalog (
                id TEXT PRIMARY KEY NOT NULL,
                engine TEXT NOT NULL,
                kind TEXT NOT NULL,
                environment TEXT NOT NULL,
                created_at TEXT NOT NULL,
                size_bytes INTEGER NOT NULL,
                checksum_algo TEXT NOT NULL,
                checksum_hex TEXT NOT NULL,
                store_key TEXT NOT NULL UNIQUE,
                codec TEXT NOT NULL,
                source_ref TEXT NOT NULL,
                parent_id TEXT NULL,
                schema_fingerprint TEXT NULL,
                encryption_provider TEXT NULL,
                encryption_recipient TEXT NULL,
                encryption_aead_id INTEGER NULL,
                secondary_store_key TEXT NULL,
                store_id TEXT NULL
            )
            SQL);
    }
}
