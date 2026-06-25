<?php

declare(strict_types=1);

namespace Vortos\Backup\Driver\ObjectStore;

use DateTimeImmutable;
use Throwable;
use Vortos\Backup\Domain\Exception\BackupException;
use Vortos\Backup\Domain\RetentionPlan;
use Vortos\Backup\Port\BackupStoreInterface;
use Vortos\Backup\Port\BackupStream;
use Vortos\Backup\Port\Capability\BackupStoreCapability;
use Vortos\Backup\Port\StoredBackup;
use Vortos\Backup\Service\ChecksumStreamFilter;
use Vortos\Backup\Service\HashStreamSink;
use Vortos\ObjectStore\Contract\ObjectStoreInterface;
use Vortos\ObjectStore\ValueObject\ListObjectsOptions;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * The default backup store: streams artifacts to the existing object store (R2 in the
 * reference stack). The provider name never appears here — it is whatever object-store
 * driver is selected — keeping the backup concern provider-agnostic.
 *
 * The checksum + size are computed in the *same* streamed pass that uploads, via a
 * read filter ({@see ChecksumStreamFilter}); bounded memory, no second read.
 *
 * Immutability / versioning / cross-region (Block 20) are honestly declared `false`.
 */
#[AsDriver('object-store')]
final class ObjectStoreBackupStore implements BackupStoreInterface
{
    public function __construct(private readonly ObjectStoreInterface $objectStore)
    {
    }

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            BackupStoreCapability::StreamingMultipart->value => true,
            BackupStoreCapability::Retention->value => true,
            BackupStoreCapability::Versioning->value => false,
            BackupStoreCapability::ObjectLock->value => false,
            BackupStoreCapability::CrossRegion->value => false,
        ]);
    }

    public function store(BackupStream $stream, string $key): StoredBackup
    {
        $resource = $stream->resource();
        $sink = new HashStreamSink();
        ChecksumStreamFilter::attach($resource, $sink);

        try {
            $this->objectStore->put($key, $resource);
        } catch (Throwable $e) {
            $this->safeDelete($key); // abort any partial object
            throw new BackupException("Failed to store backup '{$key}': " . $e->getMessage(), 0, $e);
        }

        $sink->finalize();

        return new StoredBackup($key, $sink->bytes(), $sink->checksum(), new DateTimeImmutable('now'));
    }

    public function open(string $key): mixed
    {
        return $this->objectStore->stream($key);
    }

    public function exists(string $key): bool
    {
        return $this->objectStore->exists($key);
    }

    public function delete(string $key): void
    {
        $this->objectStore->delete($key);
    }

    public function list(string $prefix): array
    {
        $out = [];
        $token = null;

        do {
            $listing = $this->objectStore->list(new ListObjectsOptions($prefix, null, $token));
            foreach ($listing->objects() as $object) {
                $out[] = ['key' => (string) $object->key(), 'size' => $object->size()];
            }
            $token = $listing->truncated() ? $listing->nextContinuationToken() : null;
        } while ($token !== null);

        return $out;
    }

    public function applyRetention(RetentionPlan $plan): void
    {
        foreach ($plan->deleteKeys() as $key) {
            $this->objectStore->delete($key);
        }
    }

    private function safeDelete(string $key): void
    {
        try {
            $this->objectStore->delete($key);
        } catch (Throwable) {
            // best effort
        }
    }
}
