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
    public function isDuplicate(string $key): bool;

    public function markSent(string $key, SentEmail $result, int $ttlSeconds = 86400): void;

    public function getSent(string $key): ?SentEmail;
}
