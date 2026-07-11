<?php

declare(strict_types=1);

namespace Vortos\Audit\Retention;

/**
 * Writes an archive segment to durable cold storage. Kept as a narrow port so the
 * ObjectStore dependency is optional and the sweeper is testable with an in-memory writer.
 * Purge NEVER runs without a real writer configured — archive-before-delete is invariant.
 */
interface AuditArchiveWriterInterface
{
    /**
     * Persist one contiguous segment as NDJSON; returns the object key it landed at.
     */
    public function write(string $chainKey, int $fromSequence, int $toSequence, string $ndjson): string;
}
