<?php
declare(strict_types=1);

namespace Vortos\Auth\Session\Storage;

/**
 * Tracks active sessions per user in Redis.
 * Key: sessions:{userId} → sorted set of {jti}:{issuedAt}
 */
final class RedisSessionStore
{
    public function __construct(private \Redis $redis) {}

    public function addSession(string $userId, string $jti, int $issuedAt, int $ttl): void
    {
        $key = "sessions:{$userId}";
        $this->redis->zAdd($key, $issuedAt, $jti);
        $this->redis->expire($key, $ttl);
    }

    public function removeSession(string $userId, string $jti): void
    {
        $this->redis->zRem("sessions:{$userId}", $jti);
    }

    public function getSessionCount(string $userId): int
    {
        return (int) $this->redis->zCard("sessions:{$userId}");
    }

    public function getOldestSession(string $userId): ?string
    {
        $result = $this->redis->zRange("sessions:{$userId}", 0, 0);
        return $result[0] ?? null;
    }

    public function removeOldestSession(string $userId): ?string
    {
        $oldest = $this->getOldestSession($userId);
        if ($oldest) {
            $this->redis->zRem("sessions:{$userId}", $oldest);
        }
        return $oldest;
    }

    public function clearAll(string $userId): void
    {
        $this->redis->del("sessions:{$userId}");
    }
}
