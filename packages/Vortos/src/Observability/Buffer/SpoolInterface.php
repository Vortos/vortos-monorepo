<?php

declare(strict_types=1);

namespace Vortos\Observability\Buffer;

/**
 * A bounded, crash-safe outbox: enqueue never blocks or throws, drain hands back records in order.
 *
 * Extracted from {@see BoundedSpool} because the *location* of the spool turns out to be a
 * correctness property, not an implementation detail. A file-backed spool is private to one
 * container, which is fine for a single long-lived process and quietly lossy for anything else:
 * under blue-green deployment the retiring color is destroyed on cutover, taking any undelivered
 * alerts with it, and a container that has no drainer (an HTTP color running FrankenPHP rather than
 * supervisord) accumulates a spool nothing will ever read.
 *
 * Implementations therefore choose the storage: {@see BoundedSpool} for a single-process deploy,
 * {@see RedisSpool} for anything with more than one container — where one drainer anywhere can flush
 * what any container enqueued, and the queue outlives the process that wrote to it.
 */
interface SpoolInterface
{
    /**
     * Append a payload. Bounded and non-blocking; returns false (counting a drop) rather than
     * growing without limit or throwing into the caller's path.
     */
    public function enqueue(string $payload, ?int $nowMs = null): bool;

    /** @return list<SpoolRecord> up to $batch records, oldest first, removed from the spool. */
    public function drain(int $batch): array;

    public function stats(?int $nowMs = null): SpoolStats;

    public function isEmpty(): bool;
}
