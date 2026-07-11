<?php

declare(strict_types=1);

namespace Vortos\Audit\Retention;

interface AuditCheckpointStoreInterface
{
    /** The current (highest-sequence) checkpoint for a chain, or null if never archived. */
    public function find(string $chainKey): ?AuditCheckpoint;

    /** Append a new checkpoint (archive runs accumulate, preserving object-key history). */
    public function save(AuditCheckpoint $checkpoint): void;
}
