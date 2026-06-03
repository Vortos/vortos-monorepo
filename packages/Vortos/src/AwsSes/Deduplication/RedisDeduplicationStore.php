<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Deduplication;

use Vortos\Cache\Contract\AtomicCacheInterface;
use Vortos\AwsSes\ValueObject\SentEmail;

/**
 * Redis-backed deduplication store.
 *
 * Single key per idempotency entry: ses_dedup:{key}
 * Value: serialised SentEmail, set atomically via NX (first-writer-wins).
 *
 * findSent() is a single GET — no TOCTOU race possible.
 * markSent() uses setNx() so only the first worker's result is persisted.
 */
final class RedisDeduplicationStore implements DeduplicationStoreInterface
{
    private const KEY_PREFIX = 'ses_dedup:';

    public function __construct(private readonly AtomicCacheInterface $cache) {}

    public function findSent(string $key): ?SentEmail
    {
        $result = $this->cache->get(self::KEY_PREFIX . $key);
        return $result instanceof SentEmail ? $result : null;
    }

    public function markSent(string $key, SentEmail $result, int $ttlSeconds = 86400): void
    {
        $this->cache->setNx(self::KEY_PREFIX . $key, $result, $ttlSeconds);
    }
}
