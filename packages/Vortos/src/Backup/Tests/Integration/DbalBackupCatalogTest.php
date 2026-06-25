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
                secondary_store_key TEXT NULL
            )
            SQL);
    }
}
