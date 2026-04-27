<?php
declare(strict_types=1);

namespace Vortos\Auth\Quota\Storage;

use Vortos\Auth\Quota\QuotaPeriod;

/**
 * Redis-backed quota counter store.
 *
 * Key format: quota:{userId}:{quota}:{period}
 * TTL: auto-set based on period — no cron needed.
 *
 * Ephemeral — Redis restart resets counters.
 * Acceptable for quota enforcement (rare event, small impact).
 */
final class RedisQuotaStore
{
    public function __construct(private \Redis $redis) {}

    public function increment(string $userId, string $quota, QuotaPeriod $period, int $cost = 1): int
    {
        $key = $this->key($userId, $quota, $period);
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
        return (int) ($this->redis->get($this->key($userId, $quota, $period)) ?: 0);
    }

    public function reset(string $userId, string $quota, QuotaPeriod $period): void
    {
        $this->redis->del($this->key($userId, $quota, $period));
    }

    private function key(string $userId, string $quota, QuotaPeriod $period): string
    {
        return "quota:{$userId}:{$quota}:{$period->getPeriodKey()}";
    }
}
