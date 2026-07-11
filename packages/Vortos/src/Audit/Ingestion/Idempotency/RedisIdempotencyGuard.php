<?php

declare(strict_types=1);

namespace Vortos\Audit\Ingestion\Idempotency;

/**
 * Cross-process guard backed by Redis SET NX EX. Lets multiple consumer instances
 * dedupe redeliveries without a DB round-trip.
 */
final class RedisIdempotencyGuard implements IdempotencyGuardInterface
{
    public function __construct(private readonly \Redis $redis) {}

    public function claim(string $key, int $ttlSeconds): bool
    {
        // SET key 1 NX EX ttl — returns true only when the key did not already exist.
        return (bool) $this->redis->set($key, '1', ['nx', 'ex' => max(1, $ttlSeconds)]);
    }

    public function release(string $key): void
    {
        $this->redis->del($key);
    }
}
