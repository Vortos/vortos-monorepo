<?php

declare(strict_types=1);

namespace Vortos\Audit\Retention\ObjectStore;

use Vortos\Audit\Retention\AuditArchiveWriterInterface;
use Vortos\ObjectStore\Contract\ImmediateObjectStoreInterface;

/**
 * Writes archive segments to a Vortos ObjectStore bucket (S3 / OCI Object Storage).
 * Object keys are deterministic and sortable so a chain's cold history reads back in
 * order: {prefix}/{chainKeyPath}/{fromSeq}-{toSeq}.ndjson.
 */
final class ObjectStoreArchiveWriter implements AuditArchiveWriterInterface
{
    public function __construct(
        // ImmediateObjectStoreInterface (not the transactional ObjectStoreInterface): the
        // retention sweep runs as a scheduled CLI command with no active DB transaction, so an
        // outbox-backed put() would fail — this maintenance path wants a direct provider write.
        private readonly ImmediateObjectStoreInterface $objectStore,
        private readonly string                        $keyPrefix = 'audit-archive',
    ) {}

    public function write(string $chainKey, int $fromSequence, int $toSequence, string $ndjson): string
    {
        $key = sprintf(
            '%s/%s/%012d-%012d.ndjson',
            trim($this->keyPrefix, '/'),
            str_replace(':', '/', $chainKey),        // 'tenant:org-1' -> 'tenant/org-1'
            $fromSequence,
            $toSequence,
        );

        $this->objectStore->put($key, $ndjson);

        return $key;
    }
}
