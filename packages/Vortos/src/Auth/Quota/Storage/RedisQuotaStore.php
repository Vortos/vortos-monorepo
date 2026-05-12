<?php
declare(strict_types=1);

namespace Vortos\Auth\Quota\Storage;

use Vortos\Auth\Quota\Exception\QuotaStoreUnavailableException;
use Vortos\Auth\Quota\QuotaConsumeResult;
use Vortos\Auth\Quota\QuotaPeriod;

/**
 * Redis-backed quota counter store.
 *
 * Key format: quota:{bucket}:{subjectId}:{quota}:{period}
 * TTL: auto-set based on period — no cron needed.
 *
 * Uses Redis TIME as the clock source for reset windows, avoiding app-server
 * clock drift across long-running FrankenPHP workers and multi-node deployments.
 */
final class RedisQuotaStore
{
    private const CONSUME_SCRIPT = <<<'LUA'
local current = tonumber(redis.call('GET', KEYS[1]) or '0')
local cost = tonumber(ARGV[1])
local limit = tonumber(ARGV[2])
local ttl = tonumber(ARGV[3])
local reset_at = tonumber(ARGV[4])

if current + cost > limit then
    return {0, current, math.max(0, limit - current), reset_at}
end

local next_value = redis.call('INCRBY', KEYS[1], cost)

if next_value == cost and ttl > 0 then
    redis.call('EXPIRE', KEYS[1], ttl)
end

return {1, next_value, math.max(0, limit - next_value), reset_at}
LUA;

    public function __construct(private \Redis $redis) {}

    public function consume(
        string $bucket,
        string $subjectId,
        string $quota,
        QuotaPeriod $period,
        int $limit,
        int $cost = 1,
    ): QuotaConsumeResult {
        if ($limit < 0) {
            throw new \InvalidArgumentException('Quota limit must be greater than or equal to zero.');
        }

        if ($cost < 1) {
            throw new \InvalidArgumentException('Quota cost must be greater than zero.');
        }

        $now = $this->redisTimestamp();
        $resetAt = $period->getResetAtTimestampAt($now);
        $ttl = $resetAt > 0 ? max(1, $resetAt - $now) : 0;
        $key = $this->key($bucket, $subjectId, $quota, $period, $now);

        try {
            /** @var array{0: int|string, 1: int|string, 2: int|string, 3: int|string}|false $result */
            $result = $this->redis->eval(self::CONSUME_SCRIPT, [$key, $cost, $limit, $ttl, $resetAt], 1);
        } catch (\RedisException $e) {
            throw new QuotaStoreUnavailableException('Quota store is unavailable.', previous: $e);
        }

        if (!is_array($result) || count($result) < 4) {
            throw new QuotaStoreUnavailableException('Quota store returned an invalid consume result.');
        }

        return new QuotaConsumeResult(
            ((int) $result[0]) === 1,
            (int) $result[1],
            (int) $result[2],
            (int) $result[3],
        );
    }

    public function increment(string $userId, string $quota, QuotaPeriod $period, int $cost = 1): int
    {
        $key = $this->key('user', $userId, $quota, $period, $this->redisTimestamp());
        $current = $this->redis->incrBy($key, $cost);

        // Set TTL only on first increment
        if ($current === $cost) {
            $ttl = $period->getTtlSeconds();
            if ($ttl > 0) {
                $this->redis->expire($key, $ttl);
            }
        }

        return $current;
    }

    public function get(string $userId, string $quota, QuotaPeriod $period): int
    {
        return (int) ($this->redis->get($this->key('user', $userId, $quota, $period, $this->redisTimestamp())) ?: 0);
    }

    public function reset(string $userId, string $quota, QuotaPeriod $period): void
    {
        $this->redis->del($this->key('user', $userId, $quota, $period, $this->redisTimestamp()));
    }

    private function key(string $bucket, string $subjectId, string $quota, QuotaPeriod $period, int $timestamp): string
    {
        return sprintf(
            'quota:%s:%s:%s:%s',
            $this->sanitizeKeyPart($bucket),
            $this->sanitizeKeyPart($subjectId),
            $this->sanitizeKeyPart($quota),
            $period->getPeriodKeyAt($timestamp),
        );
    }

    private function redisTimestamp(): int
    {
        try {
            $time = $this->redis->time();
        } catch (\RedisException $e) {
            throw new QuotaStoreUnavailableException('Quota store clock is unavailable.', previous: $e);
        }

        if (is_array($time) && isset($time[0])) {
            return (int) $time[0];
        }

        throw new QuotaStoreUnavailableException('Quota store returned an invalid clock value.');
    }

    private function sanitizeKeyPart(string $value): string
    {
        $value = trim($value);
        if ($value === '' || !preg_match('/^[A-Za-z0-9._:-]+$/', $value)) {
            return hash('sha256', $value);
        }

        return $value;
    }
}
