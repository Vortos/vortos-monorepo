<?php
declare(strict_types=1);

namespace Vortos\Auth\Session\Storage;

use Vortos\Auth\Session\Contract\SessionStoreInterface;
use Vortos\Auth\Session\SessionEnforcementResult;

final class RedisSessionStore implements SessionStoreInterface
{
    private const SESSIONS_PREFIX = 'vortos_auth:sessions:';
    private const META_PREFIX     = 'vortos_auth:session_meta:';

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
        array $meta = [],
    ): SessionEnforcementResult {
        $key = self::SESSIONS_PREFIX . $userId;

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

        $evicted = $decoded['evicted'] ?? [];

        // Evicted sessions lose their metadata too — keep the meta space bounded to live sessions.
        foreach ($evicted as $evictedJti) {
            $this->deleteMeta($userId, $evictedJti);
        }

        $this->writeMeta($userId, $jti, $meta, $ttl);

        return SessionEnforcementResult::ok($evicted);
    }

    public function addSession(string $userId, string $jti, int $issuedAt, int $ttl, array $meta = []): void
    {
        $key = self::SESSIONS_PREFIX . $userId;
        $this->redis->zAdd($key, $issuedAt, $jti);

        $currentTtl = $this->redis->ttl($key);
        if ($currentTtl < $ttl) {
            $this->redis->expire($key, $ttl);
        }

        $this->writeMeta($userId, $jti, $meta, $ttl);
    }

    public function removeSession(string $userId, string $jti): void
    {
        $this->redis->zRem(self::SESSIONS_PREFIX . $userId, $jti);
        $this->deleteMeta($userId, $jti);
    }

    public function getSessionCount(string $userId): int
    {
        return (int) $this->redis->zCard(self::SESSIONS_PREFIX . $userId);
    }

    public function getOldestSession(string $userId): ?string
    {
        $result = $this->redis->zRange(self::SESSIONS_PREFIX . $userId, 0, 0);
        return $result[0] ?? null;
    }

    public function removeOldestSession(string $userId): ?string
    {
        $oldest = $this->getOldestSession($userId);
        if ($oldest) {
            $this->redis->zRem(self::SESSIONS_PREFIX . $userId, $oldest);
            $this->deleteMeta($userId, $oldest);
        }
        return $oldest;
    }

    public function clearAll(string $userId): void
    {
        // Drop every session's metadata before the ZSET, so no meta keys are orphaned.
        foreach (array_keys($this->listSessions($userId)) as $jti) {
            $this->deleteMeta($userId, $jti);
        }
        $this->redis->del(self::SESSIONS_PREFIX . $userId);
    }

    /**
     * @return array<string, int> jti => issuedAt (the ZSET score)
     */
    public function listSessions(string $userId): array
    {
        /** @var array<string, mixed> $raw */
        $raw = $this->redis->zRange(self::SESSIONS_PREFIX . $userId, 0, -1, ['withscores' => true]);

        $sessions = [];
        foreach ($raw as $jti => $score) {
            $sessions[(string) $jti] = (int) $score;
        }

        return $sessions;
    }

    public function listSessionsWithMeta(string $userId): array
    {
        $sessions = $this->listSessions($userId);
        if ($sessions === []) {
            return [];
        }

        $jtis    = array_keys($sessions);
        $metaKeys = array_map(fn(string $jti) => self::META_PREFIX . $userId . ':' . $jti, $jtis);

        /** @var array<int, mixed> $rawMetas */
        $rawMetas = $this->redis->mGet($metaKeys);

        $result = [];
        foreach ($jtis as $i => $jti) {
            $raw  = $rawMetas[$i] ?? false;
            $meta = is_string($raw) ? (json_decode($raw, true) ?: []) : [];

            $result[$jti] = [
                'issued_at' => $sessions[$jti],
                'meta'      => is_array($meta) ? $meta : [],
            ];
        }

        return $result;
    }

    public function getSessionMeta(string $userId, string $jti): array
    {
        $raw = $this->redis->get(self::META_PREFIX . $userId . ':' . $jti);
        if (!is_string($raw)) {
            return [];
        }
        $meta = json_decode($raw, true);

        return is_array($meta) ? $meta : [];
    }

    public function hasSession(string $userId, string $jti): bool
    {
        return $this->redis->zScore(self::SESSIONS_PREFIX . $userId, $jti) !== false;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function writeMeta(string $userId, string $jti, array $meta, int $ttl): void
    {
        if ($meta === [] || $ttl <= 0) {
            return;
        }
        $this->redis->setex(
            self::META_PREFIX . $userId . ':' . $jti,
            $ttl,
            json_encode($meta, JSON_THROW_ON_ERROR),
        );
    }

    private function deleteMeta(string $userId, string $jti): void
    {
        $this->redis->del(self::META_PREFIX . $userId . ':' . $jti);
    }
}
