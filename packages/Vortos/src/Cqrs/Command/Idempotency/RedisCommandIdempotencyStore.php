<?php

declare(strict_types=1);

namespace Vortos\Cqrs\Command\Idempotency;

use Vortos\Cache\Contract\AtomicCacheInterface;

/**
 * Redis-backed command idempotency store.
 *
 * Default implementation — uses the PSR-16 CacheInterface already wired
 * to Redis by the messaging module. Zero additional infrastructure required.
 *
 * ## Performance
 *
 * Redis GET is sub-millisecond. This check adds negligible latency to
 * every command dispatch — acceptable for the protection it provides.
 *
 * ## Key format
 *
 * Cache key: vortos_cmd_idempotency_{idempotencyKey}
 * Value: '1' (minimal storage — only existence matters, not the value)
 *
 * ## TTL
 *
 * Default TTL is 86400 seconds (24 hours). Configurable per call.
 * After TTL expires, the same idempotency key can be reused.
 *
 * ## Limitations
 *
 * Redis is in-memory — keys are lost on Redis restart (without persistence).
 * For audit-grade idempotency that must survive Redis restart, use
 * DbalCommandIdempotencyStore instead.
 */
final class RedisCommandIdempotencyStore implements CommandIdempotencyStoreInterface
{
    private const KEY_PREFIX = 'vortos_cmd_idempotency_';

    public function __construct(private AtomicCacheInterface $cache) {}

    /**
     * {@inheritdoc}
     */
    public function wasProcessed(string $idempotencyKey): bool
    {
        return $this->cache->has(self::KEY_PREFIX . $idempotencyKey);
    }

    /**
     * {@inheritdoc}
     */
    public function markProcessed(string $idempotencyKey, int $ttl = 86400): void
    {
        $this->cache->set(self::KEY_PREFIX . $idempotencyKey, '1', $ttl);
    }

    /**
     * {@inheritdoc}
     */
    public function tryMarkProcessed(string $idempotencyKey, int $ttl = 86400): bool
    {
        return $this->cache->setNx(self::KEY_PREFIX . $idempotencyKey, '1', $ttl);
    }

    /**
     * {@inheritdoc}
     */
    public function releaseProcessed(string $idempotencyKey): void
    {
        $this->cache->delete(self::KEY_PREFIX . $idempotencyKey);
    }
}
