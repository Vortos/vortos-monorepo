<?php

declare(strict_types=1);

namespace Vortos\AwsSes\RateLimit;

/**
 * Distributed token bucket backed by a Redis Lua script.
 *
 * The entire refill-and-consume operation is a single atomic Lua eval(),
 * so it is safe across any number of concurrent workers and servers.
 *
 * ## Algorithm
 *   1. Read current token count and last refill timestamp from Redis.
 *   2. Compute elapsed seconds since last refill.
 *   3. Add (elapsed × maxRate) new tokens, capped at burst.
 *   4. If tokens ≥ 1 consume one and return 1 (success); else return 0.
 *   5. Persist new state with a TTL to avoid stale keys.
 *
 * Keys:
 *   {keyPrefix}:tokens  — float token count
 *   {keyPrefix}:ts      — Unix timestamp (float, microseconds)
 *
 * TTL: burst ÷ maxRate × 2 seconds, so an idle bucket naturally expires.
 */
final class RedisTokenBucket implements TokenBucketInterface
{
    private const LUA = <<<'LUA'
local kt     = KEYS[1]
local kts    = KEYS[2]
local rate   = tonumber(ARGV[1])
local burst  = tonumber(ARGV[2])
local now    = tonumber(ARGV[3])
local ttl    = tonumber(ARGV[4])

local tokens  = tonumber(redis.call('GET', kt))  or burst
local last_ts = tonumber(redis.call('GET', kts)) or now

local elapsed = math.max(0, now - last_ts)
tokens = math.min(burst, tokens + elapsed * rate)

local consumed = 0
if tokens >= 1.0 then
    tokens    = tokens - 1.0
    consumed  = 1
end

redis.call('SET', kt,  tokens, 'EX', ttl)
redis.call('SET', kts, now,    'EX', ttl)

return consumed
LUA;

    private readonly int $keyTtl;

    public function __construct(
        private readonly \Redis $redis,
        private readonly int $maxRate,
        private readonly int $burst,
        private readonly string $keyPrefix = 'ses_rate_bucket',
    ) {
        // TTL: enough time for a full bucket to drain at max rate, ×2 safety margin
        $this->keyTtl = max(60, (int) ceil($burst / max(1, $maxRate) * 2));
    }

    public function tryConsume(): bool
    {
        $result = $this->redis->eval(
            self::LUA,
            [$this->keyPrefix . ':tokens', $this->keyPrefix . ':ts',
             (string) $this->maxRate,
             (string) $this->burst,
             (string) microtime(true),
             (string) $this->keyTtl,
            ],
            2,
        );

        return (int) $result === 1;
    }
}
