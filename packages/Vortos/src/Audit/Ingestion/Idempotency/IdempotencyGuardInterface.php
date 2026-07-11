<?php

declare(strict_types=1);

namespace Vortos\Audit\Ingestion\Idempotency;

/**
 * Fast-path duplicate-delivery guard for the ingestion consumer. The DB primary key on
 * the event id is the authoritative dedup; this just avoids the DB round-trip for a
 * message the consumer has already handled (e.g. a Kafka redelivery after a crash).
 */
interface IdempotencyGuardInterface
{
    /**
     * Atomically claim an id. Returns true if newly claimed (caller should process),
     * false if already claimed (caller should skip).
     */
    public function claim(string $key, int $ttlSeconds): bool;

    /** Release a claim so a failed attempt can be retried on redelivery. */
    public function release(string $key): void;
}
