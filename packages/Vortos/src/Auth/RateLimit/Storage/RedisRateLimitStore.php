<?php
declare(strict_types=1);

namespace Vortos\Auth\RateLimit\Storage;

use Vortos\Auth\RateLimit\Contract\RateLimitStoreInterface;
use Vortos\Auth\RateLimit\Exception\RateLimitStoreUnavailableException;

final class RedisRateLimitStore implements RateLimitStoreInterface
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
        try {
            return (int) $this->redis->eval($script, [$key, (string) $windowSeconds], 1);
        } catch (\Throwable $e) {
            throw new RateLimitStoreUnavailableException('Rate limit store is unavailable.', previous: $e);
        }
    }

    public function getTtl(string $key): int
    {
        try {
            return max(0, $this->redis->ttl($key));
        } catch (\Throwable $e) {
            throw new RateLimitStoreUnavailableException('Rate limit store is unavailable.', previous: $e);
        }
    }

    public function reset(string $key): void
    {
        try {
            $this->redis->del($key);
        } catch (\Throwable $e) {
            throw new RateLimitStoreUnavailableException('Rate limit store is unavailable.', previous: $e);
        }
    }
}
