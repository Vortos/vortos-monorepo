<?php
declare(strict_types=1);

namespace Vortos\Auth\Session\Storage;

use Vortos\Auth\Session\Contract\SessionStoreInterface;
use Vortos\Auth\Session\SessionEnforcementResult;

final class RedisSessionStore implements SessionStoreInterface
{
    private const ENFORCE_AND_ADD_LUA = <<<'LUA'
local key = KEYS[1]
local max = tonumber(ARGV[1])
local jti = ARGV[2]
local score = tonumber(ARGV[3])
local ttl = tonumber(ARGV[4])
local action = tonumber(ARGV[5])
local evicted = {}

local count = redis.call('ZCARD', key)
while count >= max do
    if action == 0 then
        return cjson.encode({status='rejected', evicted={}})
    end
    local oldest = redis.call('ZRANGE', key, 0, 0)
    if #oldest == 0 then break end
    redis.call('ZREM', key, oldest[1])
    table.insert(evicted, oldest[1])
    count = count - 1
end

redis.call('ZADD', key, score, jti)

local curTtl = redis.call('TTL', key)
if curTtl < ttl then
    redis.call('EXPIRE', key, ttl)
end

return cjson.encode({status='ok', evicted=evicted})
LUA;

    public function __construct(private \Redis $redis) {}

    /**
     * Atomically enforce the session limit and add the new session in a single Lua call.
     *
     * @param bool $evictOldest true = evict oldest sessions to make room; false = reject if at capacity
     */
    public function enforceAndAdd(
        string $userId,
        string $jti,
        int $issuedAt,
        int $ttl,
        int $maxSessions,
        bool $evictOldest,
    ): SessionEnforcementResult {
        $key = "vortos_auth:sessions:{$userId}";

        /** @var string $raw */
        $raw = $this->redis->eval(
            self::ENFORCE_AND_ADD_LUA,
            [$key, (string) $maxSessions, $jti, (string) $issuedAt, (string) $ttl, $evictOldest ? '1' : '0'],
            1,
        );

        $decoded = json_decode($raw, true);

        if (($decoded['status'] ?? '') === 'rejected') {
            return SessionEnforcementResult::rejected();
        }

        return SessionEnforcementResult::ok($decoded['evicted'] ?? []);
    }

    public function addSession(string $userId, string $jti, int $issuedAt, int $ttl): void
    {
        $key = "vortos_auth:sessions:{$userId}";
        $this->redis->zAdd($key, $issuedAt, $jti);

        $currentTtl = $this->redis->ttl($key);
        if ($currentTtl < $ttl) {
            $this->redis->expire($key, $ttl);
        }
    }

    public function removeSession(string $userId, string $jti): void
    {
        $this->redis->zRem("vortos_auth:sessions:{$userId}", $jti);
    }

    public function getSessionCount(string $userId): int
    {
        return (int) $this->redis->zCard("vortos_auth:sessions:{$userId}");
    }

    public function getOldestSession(string $userId): ?string
    {
        $result = $this->redis->zRange("vortos_auth:sessions:{$userId}", 0, 0);
        return $result[0] ?? null;
    }

    public function removeOldestSession(string $userId): ?string
    {
        $oldest = $this->getOldestSession($userId);
        if ($oldest) {
            $this->redis->zRem("vortos_auth:sessions:{$userId}", $oldest);
        }
        return $oldest;
    }

    public function clearAll(string $userId): void
    {
        $this->redis->del("vortos_auth:sessions:{$userId}");
    }

    /**
     * @return array<string, int> jti => issuedAt (the ZSET score)
     */
    public function listSessions(string $userId): array
    {
        /** @var array<string, mixed> $raw */
        $raw = $this->redis->zRange("vortos_auth:sessions:{$userId}", 0, -1, ['withscores' => true]);

        $sessions = [];
        foreach ($raw as $jti => $score) {
            $sessions[(string) $jti] = (int) $score;
        }

        return $sessions;
    }

    public function hasSession(string $userId, string $jti): bool
    {
        return $this->redis->zScore("vortos_auth:sessions:{$userId}", $jti) !== false;
    }
}
