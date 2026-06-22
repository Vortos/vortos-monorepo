<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\ReadModel;

/**
 * Read repository (port) for the append-only flag audit log.
 */
interface FlagAuditLogRepositoryInterface
{
    /** Idempotent upsert (keyed by event id). */
    public function upsert(FlagAuditEntry $entry): void;

    /**
     * History for one flag, newest first.
     *
     * @return list<FlagAuditEntry>
     */
    public function findByFlag(string $flagName, int $limit = 100): array;

    /**
     * Stream all audit entries matching the filter in oldest-first order.
     * Memory-bounded: yields batches, never loads the full result into memory.
     * Implementations may return all matching entries at once for in-memory stores.
     *
     * @return \Generator<int, FlagAuditEntry>
     */
    public function stream(\Vortos\FeatureFlags\Compliance\Export\AuditExportFilter $filter): \Generator;
}
