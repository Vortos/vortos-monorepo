<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Deduplication;

use Vortos\AwsSes\ValueObject\SentEmail;

/**
 * Stores and looks up idempotency keys for deduplication.
 *
 * Implementations: InMemoryDeduplicationStore (single process),
 * or a Redis-backed store (multi-process / multi-server).
 */
interface DeduplicationStoreInterface
{
    /**
     * Returns the stored SentEmail for the given key, or null if not found.
     *
     * Single call replaces the former isDuplicate() + getSent() pattern, eliminating
     * the TOCTOU race between the two separate reads.
     */
    public function findSent(string $key): ?SentEmail;

    /**
     * Persists the send result so future findSent() calls return it.
     *
     * Implementations MUST use first-writer-wins semantics (no overwrite on duplicate key).
     */
    public function markSent(string $key, SentEmail $result, int $ttlSeconds = 86400): void;
}
