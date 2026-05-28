<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Deduplication;

use Vortos\Cache\Contract\AtomicCacheInterface;
use Vortos\AwsSes\ValueObject\SentEmail;

/**
 * Redis-backed deduplication store.
 *
 * Uses AtomicCacheInterface::setNx() (Redis SET NX EX) for atomic mark-and-check,
 * eliminating the TOCTOU race that exists with separate has() + set() calls.
 *
 * Two keys per idempotency entry:
 *   ses_dedup:{key}:flag    — NX sentinel (present = sent)
 *   ses_dedup:{key}:result  — serialised SentEmail for returning to caller
 *
 * Both keys share the same TTL so they expire together.
 */
final class RedisDeduplicationStore implements DeduplicationStoreInterface
{
    private const KEY_PREFIX = 'ses_dedup:';

    public function __construct(private readonly AtomicCacheInterface $cache) {}

    public function isDuplicate(string $key): bool
    {
        return $this->cache->has(self::KEY_PREFIX . $key . ':flag');
    }

    public function markSent(string $key, SentEmail $result, int $ttlSeconds = 86400): void
    {
        $flagKey   = self::KEY_PREFIX . $key . ':flag';
        $resultKey = self::KEY_PREFIX . $key . ':result';

        // Atomic: only write if this is the first worker to finish
        $written = $this->cache->setNx($flagKey, true, $ttlSeconds);

        if ($written) {
            $this->cache->set($resultKey, $result, $ttlSeconds);
        }
    }

    public function getSent(string $key): ?SentEmail
    {
        $result = $this->cache->get(self::KEY_PREFIX . $key . ':result');

        return $result instanceof SentEmail ? $result : null;
    }
}
