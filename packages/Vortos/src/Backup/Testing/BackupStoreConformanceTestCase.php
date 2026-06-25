<?php

declare(strict_types=1);

namespace Vortos\Backup\Testing;

use Vortos\Backup\Domain\BackupChecksum;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\CompressionCodec;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Domain\RetentionPlan;
use Vortos\Backup\Domain\SourceRef;
use Vortos\Backup\Port\BackupStoreInterface;
use Vortos\Backup\Port\BackupStream;
use Vortos\Backup\Port\Capability\BackupStoreCapability;
use Vortos\OpsKit\Testing\ConformanceTestCase;

/**
 * The TCK every {@see BackupStoreInterface} driver must pass: the universal OpsKit
 * contract plus the store round-trip (store → exists → open/read-back → list → delete)
 * with a streaming checksum that matches the bytes written.
 */
abstract class BackupStoreConformanceTestCase extends ConformanceTestCase
{
    final protected function store(): BackupStoreInterface
    {
        $driver = $this->createDriver();
        self::assertInstanceOf(BackupStoreInterface::class, $driver);

        return $driver;
    }

    final public function test_streaming_multipart_capability_is_declared(): void
    {
        self::assertTrue($this->store()->capabilities()->supports(BackupStoreCapability::StreamingMultipart));
    }

    final public function test_store_open_list_delete_round_trip(): void
    {
        $store = $this->store();
        $payload = random_bytes(4096);
        $key = 'tck/round-trip.bin';

        $stored = $store->store($this->streamOf($payload), $key);

        self::assertSame($key, $stored->storeKey);
        self::assertSame(strlen($payload), $stored->sizeBytes);
        self::assertTrue($stored->checksum->equals(BackupChecksum::ofString($payload)), 'Stored checksum must match the written bytes.');

        self::assertTrue($store->exists($key));

        $read = stream_get_contents($store->open($key));
        self::assertSame($payload, $read, 'Read-back bytes must equal what was stored.');

        $listed = $store->list('tck/');
        self::assertContains($key, array_column($listed, 'key'));

        $store->delete($key);
        self::assertFalse($store->exists($key));
    }

    final public function test_apply_retention_deletes_only_planned_keys(): void
    {
        $store = $this->store();
        $store->store($this->streamOf('keep'), 'tck/keep.bin');
        $store->store($this->streamOf('drop'), 'tck/drop.bin');

        // An empty plan deletes nothing.
        $store->applyRetention(new RetentionPlan([], [], []));
        self::assertTrue($store->exists('tck/keep.bin'));
        self::assertTrue($store->exists('tck/drop.bin'));

        $store->delete('tck/keep.bin');
        $store->delete('tck/drop.bin');
    }

    private function streamOf(string $data): BackupStream
    {
        $resource = fopen('php://temp', 'r+b');
        self::assertIsResource($resource);
        fwrite($resource, $data);
        rewind($resource);

        return new BackupStream($resource, DatabaseEngine::Postgres, BackupKind::LogicalFull, CompressionCodec::None, SourceRef::none());
    }
}
