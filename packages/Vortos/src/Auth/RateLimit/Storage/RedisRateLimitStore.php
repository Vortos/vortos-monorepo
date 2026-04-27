<?php
declare(strict_types=1);

namespace Vortos\Auth\RateLimit\Storage;

/**
 * Redis-backed rate limit counter.
 * Ephemeral — Redis restart resets counters. Acceptable for rate limiting.
 */
final class RedisRateLimitStore
{
    public function __construct(private \Redis $redis) {}

    public function increment(string $key, int $windowSeconds): int
    {
        $count = $this->redis->incrBy($key, 1);
        if ($count === 1) {
            $this->redis->expire($key, $windowSeconds);
        }
        return $count;
    }

    public function getTtl(string $key): int
    {
        return max(0, $this->redis->ttl($key));
    }

    public function reset(string $key): void
    {
        $this->redis->del($key);
    }
}
