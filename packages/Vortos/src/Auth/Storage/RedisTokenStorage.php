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

    private const LUA_CONSUME = <<<'LUA'
local key = KEYS[1]
local jti = ARGV[1]
local userPrefix = ARGV[2]
local userId = redis.call('GET', key)
if not userId then
    return false
end
redis.call('DEL', key)
redis.call('SREM', userPrefix .. userId, jti)
return userId
LUA;

    private const LUA_REVOKE = <<<'LUA'
local key = KEYS[1]
local jti = ARGV[1]
local userPrefix = ARGV[2]
local userId = redis.call('GET', key)
redis.call('DEL', key)
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

    public function __construct(private \Redis $redis) {}

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
            [self::TOKEN_PREFIX . $jti, $jti, self::USER_TOKENS_PREFIX],
            1,
        );

        return $result !== false ? (string) $result : null;
    }

    public function revoke(string $jti): void
    {
        $this->redis->eval(
            self::LUA_REVOKE,
            [self::TOKEN_PREFIX . $jti, $jti, self::USER_TOKENS_PREFIX],
            1,
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
