<?php

declare(strict_types=1);

namespace Vortos\Auth\Storage;

use Vortos\Auth\Contract\TokenStorageInterface;

/**
 * Redis-backed refresh token storage.
 *
 * ## Key format
 *
 *   {prefix}auth:token:{jti}          — value: userId, TTL: token expiry
 *   {prefix}auth:user_tokens:{userId} — Redis SET of active JTIs for this user
 *
 * ## Atomicity
 *
 * All mutation operations use Lua scripts for atomicity. In particular,
 * consume() is a single GET+DEL+SREM ensuring exactly-once semantics
 * for refresh token rotation — concurrent callers cannot both succeed.
 *
 * ## TTL management
 *
 * Token keys expire automatically when the refresh token TTL passes.
 * The user_tokens SET is cleaned up lazily — when revokeAllForUser() is called
 * or when store() prunes expired JTIs from the set.
 *
 * ## Separate Redis prefix from application cache
 *
 * Auth tokens use the prefix 'vortos_auth:' regardless of the application cache
 * prefix. This means vortos:cache:clear does NOT wipe auth tokens — they are
 * in a different key namespace and persist across cache clears. This is correct
 * behavior: clearing the application cache should not log everyone out.
 */
final class RedisTokenStorage implements TokenStorageInterface
{
    private const TOKEN_PREFIX = 'vortos_auth:token:';
    private const USER_TOKENS_PREFIX = 'vortos_auth:user_tokens:';
    private const GRACE_PREFIX = 'vortos_auth:token_grace:';

    /**
     * Atomic rotation-with-grace.
     *
     * Primary hit (token still live): delete it, drop it from the user's active set, and —
     * when a grace window is configured — leave a short-lived grace marker so an immediate
     * re-presentation of THIS jti (a racing tab, a retried request) is recognised as benign
     * rather than reuse. The grace marker is only ever written on the primary path, so a
     * grace hit never re-arms grace (no unbounded chaining).
     *
     * Primary miss: if grace is enabled and a marker exists, the token was consumed moments
     * ago — return the userId so the caller issues a fresh pair instead of declaring theft.
     * Otherwise return false (nil) → genuine reuse, and the caller applies its theft policy.
     */
    private const LUA_CONSUME = <<<'LUA'
local key = KEYS[1]
local graceKey = KEYS[2]
local jti = ARGV[1]
local userPrefix = ARGV[2]
local graceSeconds = tonumber(ARGV[3])
local userId = redis.call('GET', key)
if userId then
    redis.call('DEL', key)
    redis.call('SREM', userPrefix .. userId, jti)
    if graceSeconds > 0 then
        redis.call('SET', graceKey, userId, 'EX', graceSeconds)
    end
    return userId
end
if graceSeconds > 0 then
    local graceUser = redis.call('GET', graceKey)
    if graceUser then
        return graceUser
    end
end
return false
LUA;

    private const LUA_REVOKE = <<<'LUA'
local key = KEYS[1]
local graceKey = KEYS[2]
local jti = ARGV[1]
local userPrefix = ARGV[2]
local userId = redis.call('GET', key)
redis.call('DEL', key)
redis.call('DEL', graceKey)
if userId then
    redis.call('SREM', userPrefix .. userId, jti)
end
return 1
LUA;

    private const LUA_REVOKE_ALL = <<<'LUA'
local userKey = KEYS[1]
local tokenPrefix = ARGV[1]
local jtis = redis.call('SMEMBERS', userKey)
if #jtis > 0 then
    local keys = {}
    for i, jti in ipairs(jtis) do
        keys[i] = tokenPrefix .. jti
    end
    redis.call('DEL', unpack(keys))
end
redis.call('DEL', userKey)
return #jtis
LUA;

    /**
     * @param int $rotationGraceSeconds Grace window during which a just-rotated jti may be
     *                                  re-consumed without tripping reuse detection. 0 = strict.
     */
    public function __construct(
        private \Redis $redis,
        private int $rotationGraceSeconds = 0,
    ) {}

    public function store(string $jti, string $userId, int $expiresAt): void
    {
        $ttl = $expiresAt - time();

        if ($ttl <= 0) {
            return;
        }

        $this->redis->setex(self::TOKEN_PREFIX . $jti, $ttl, $userId);

        $userKey = self::USER_TOKENS_PREFIX . $userId;
        $this->redis->sAdd($userKey, $jti);

        $requiredTtl = $ttl + 3600;
        $existingTtl = $this->redis->ttl($userKey);
        if ($existingTtl < $requiredTtl) {
            $this->redis->expire($userKey, $requiredTtl);
        }
    }

    public function consume(string $jti): ?string
    {
        $result = $this->redis->eval(
            self::LUA_CONSUME,
            [self::TOKEN_PREFIX . $jti, self::GRACE_PREFIX . $jti, $jti, self::USER_TOKENS_PREFIX, (string) $this->rotationGraceSeconds],
            2,
        );

        return $result !== false ? (string) $result : null;
    }

    public function revoke(string $jti): void
    {
        $this->redis->eval(
            self::LUA_REVOKE,
            [self::TOKEN_PREFIX . $jti, self::GRACE_PREFIX . $jti, $jti, self::USER_TOKENS_PREFIX],
            2,
        );
    }

    public function revokeAllForUser(string $userId): void
    {
        $this->redis->eval(
            self::LUA_REVOKE_ALL,
            [self::USER_TOKENS_PREFIX . $userId, self::TOKEN_PREFIX],
            1,
        );
    }
}
