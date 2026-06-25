<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Vortos\Backup\Domain\BackupChecksum;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\CompressionCodec;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Domain\Exception\IntegrityException;
use Vortos\Backup\Driver\ObjectStore\ObjectStoreBackupStore;
use Vortos\Backup\Port\BackupStream;
use Vortos\Backup\Domain\SourceRef;
use Vortos\Backup\Service\IntegrityVerifier;
use Vortos\Backup\Tests\Support\InMemoryObjectStore;

final class IntegrityVerifierTest extends TestCase
{
    private function storeWith(string $key, string $bytes): ObjectStoreBackupStore
    {
        $object = new InMemoryObjectStore();
        $store = new ObjectStoreBackupStore($object);
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $bytes);
        rewind($stream);
        $store->store(new BackupStream($stream, DatabaseEngine::Postgres, BackupKind::LogicalFull, CompressionCodec::None, SourceRef::none()), $key);

        return $store;
    }

    public function test_valid_pg_custom_format_passes(): void
    {
        $bytes = 'PGDMP' . random_bytes(2000);
        $store = $this->storeWith('k', $bytes);

        (new IntegrityVerifier())->verify($store, 'k', BackupChecksum::ofString($bytes), DatabaseEngine::Postgres, BackupKind::LogicalFull, CompressionCodec::None);
        $this->addToAssertionCount(1);
    }

    public function test_gzip_codec_requires_gzip_magic(): void
    {
        $bytes = "\x1f\x8b" . random_bytes(2000);
        $store = $this->storeWith('k', $bytes);

        (new IntegrityVerifier())->verify($store, 'k', BackupChecksum::ofString($bytes), DatabaseEngine::Mongo, BackupKind::MongoArchive, CompressionCodec::Gzip);
        $this->addToAssertionCount(1);
    }

    public function test_unrecognised_format_fails(): void
    {
        $bytes = 'NOTPG' . random_bytes(2000);
        $store = $this->storeWith('k', $bytes);

        $this->expectException(IntegrityException::class);
        (new IntegrityVerifier())->verify($store, 'k', BackupChecksum::ofString($bytes), DatabaseEngine::Postgres, BackupKind::LogicalFull, CompressionCodec::None);
    }

    public function test_checksum_mismatch_fails(): void
    {
        $bytes = 'PGDMP' . random_bytes(2000);
        $store = $this->storeWith('k', $bytes);

        $this->expectException(IntegrityException::class);
        (new IntegrityVerifier())->verify($store, 'k', BackupChecksum::ofString('something-else'), DatabaseEngine::Postgres, BackupKind::LogicalFull, CompressionCodec::None);
    }

    public function test_corrupt_gzip_magic_fails(): void
    {
        $bytes = "\x00\x00corrupt";
        $store = $this->storeWith('k', $bytes);

        $this->expectException(IntegrityException::class);
        (new IntegrityVerifier())->verify($store, 'k', BackupChecksum::ofString($bytes), DatabaseEngine::Mongo, BackupKind::MongoArchive, CompressionCodec::Gzip);
    }
}
