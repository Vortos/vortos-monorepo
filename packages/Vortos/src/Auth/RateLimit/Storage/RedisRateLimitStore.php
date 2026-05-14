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
        $script = <<<'LUA'
local current = redis.call('INCRBY', KEYS[1], 1)
if current == 1 then
    redis.call('EXPIRE', KEYS[1], tonumber(ARGV[1]))
end
return current
LUA;
        return (int) $this->redis->eval($script, [$key, (string) $windowSeconds], 1);
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
