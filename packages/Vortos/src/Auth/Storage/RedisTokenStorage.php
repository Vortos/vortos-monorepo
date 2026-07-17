<?php

declare(strict_types=1);

namespace Vortos\Auth\Storage;

use Vortos\Auth\Contract\TokenStorageInterface;
use Vortos\Auth\Storage\TokenConsumeResult;

/**
 * Redis-backed refresh token storage.
 *
 * ## Key format
 *
 *   {prefix}auth:token:{jti}          — value: userId, TTL: token expiry
 *   {prefix}auth:user_tokens:{userId} — Redis SET of active JTIs for this user
 *   {prefix}auth:token_grace:{jti}    — short-lived rotation-grace marker (benign re-use window)
 *   {prefix}auth:token_revoked:{jti}  — revocation tombstone: this JTI was deliberately revoked
 *
 * ## Revoked vs. reused
 *
 * revoke() does not merely delete the token — it leaves a tombstone that outlives the
 * token's own natural expiry. A later consume() of a tombstoned JTI reports Revoked
 * (deliberate sign-out) rather than Reused (theft), so a revoked device firing one last
 * refresh is rejected in isolation instead of tripping the RFC 6819 breach response that
 * would revoke every session the user has.
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
    private const REVOKED_PREFIX = 'vortos_auth:token_revoked:';

    /**
     * Atomic rotation-with-grace, classifying every outcome.
     *
     * Primary hit (token still live): delete it, drop it from the user's active set, and —
     * when a grace window is configured — leave a short-lived grace marker so an immediate
     * re-presentation of THIS jti (a racing tab, a retried request) is recognised as benign
     * rather than reuse. The grace marker is only ever written on the primary path, so a
     * grace hit never re-arms grace (no unbounded chaining). → status 'rotated'.
     *
     * Primary miss: classify why the token is gone —
     *   - a revocation tombstone exists → the session was deliberately signed out → 'revoked'.
     *   - a grace marker exists (and grace enabled) → benign just-rotated race → 'rotated'.
     *   - neither → the token was consumed and never revoked → genuine reuse → 'reused'.
     *
     * Revoked is checked before grace so a deliberate revoke always wins over a lingering
     * grace marker. Returns a cjson-encoded {status, userId}.
     */
    private const LUA_CONSUME = <<<'LUA'
local key = KEYS[1]
local graceKey = KEYS[2]
local revokedKey = KEYS[3]
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
    return cjson.encode({status='rotated', userId=userId})
end
if redis.call('EXISTS', revokedKey) == 1 then
    return cjson.encode({status='revoked'})
end
if graceSeconds > 0 then
    local graceUser = redis.call('GET', graceKey)
    if graceUser then
        return cjson.encode({status='rotated', userId=graceUser})
    end
end
return cjson.encode({status='reused'})
LUA;

    /**
     * Revoke-with-tombstone.
     *
     * Delete the token and its grace marker, drop it from the user's active set, and write a
     * revocation tombstone so a later consume() of this jti is classified 'revoked' rather
     * than 'reused'. The tombstone inherits the token's remaining TTL when the token is still
     * live (the common case — revoking an active session), so it survives exactly as long as
     * the refresh token could have been replayed; if the token is already gone we fall back to
     * the configured tombstone TTL.
     */
    private const LUA_REVOKE = <<<'LUA'
local key = KEYS[1]
local graceKey = KEYS[2]
local revokedKey = KEYS[3]
local jti = ARGV[1]
local userPrefix = ARGV[2]
local fallbackTtl = tonumber(ARGV[3])
local userId = redis.call('GET', key)
local ttl = redis.call('TTL', key)
if ttl == nil or ttl < 0 then
    ttl = fallbackTtl
end
redis.call('DEL', key)
redis.call('DEL', graceKey)
if userId then
    redis.call('SREM', userPrefix .. userId, jti)
end
if ttl > 0 then
    redis.call('SET', revokedKey, '1', 'EX', ttl)
end
return 1
LUA;

    private const LUA_REVOKE_ALL = <<<'LUA'
local userKey = KEYS[1]
local tokenPrefix = ARGV[1]
local revokedPrefix = ARGV[2]
local fallbackTtl = tonumber(ARGV[3])
local jtis = redis.call('SMEMBERS', userKey)
for _, jti in ipairs(jtis) do
    local ttl = redis.call('TTL', tokenPrefix .. jti)
    if ttl == nil or ttl < 0 then
        ttl = fallbackTtl
    end
    redis.call('DEL', tokenPrefix .. jti)
    if ttl > 0 then
        redis.call('SET', revokedPrefix .. jti, '1', 'EX', ttl)
    end
end
redis.call('DEL', userKey)
return #jtis
LUA;

    /**
     * @param int $rotationGraceSeconds     Grace window during which a just-rotated jti may be
     *                                       re-consumed without tripping reuse detection. 0 = strict.
     * @param int $revocationTombstoneTtl    Fallback TTL (seconds) for a revocation tombstone when
     *                                       the revoked token's own remaining TTL is unknown. Should
     *                                       be at least the refresh-token TTL so a revoked token can
     *                                       never outlive its tombstone. Default: 604800 (7 days).
     */
    public function __construct(
        private \Redis $redis,
        private int $rotationGraceSeconds = 0,
        private int $revocationTombstoneTtl = 604800,
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

    public function consume(string $jti): TokenConsumeResult
    {
        /** @var string $raw */
        $raw = $this->redis->eval(
            self::LUA_CONSUME,
            [
                self::TOKEN_PREFIX . $jti,
                self::GRACE_PREFIX . $jti,
                self::REVOKED_PREFIX . $jti,
                $jti,
                self::USER_TOKENS_PREFIX,
                (string) $this->rotationGraceSeconds,
            ],
            3,
        );

        $decoded = json_decode($raw, true);

        return match ($decoded['status'] ?? 'reused') {
            'rotated' => TokenConsumeResult::rotated((string) $decoded['userId']),
            'revoked' => TokenConsumeResult::revoked(),
            default   => TokenConsumeResult::reused(),
        };
    }

    public function revoke(string $jti): void
    {
        $this->redis->eval(
            self::LUA_REVOKE,
            [
                self::TOKEN_PREFIX . $jti,
                self::GRACE_PREFIX . $jti,
                self::REVOKED_PREFIX . $jti,
                $jti,
                self::USER_TOKENS_PREFIX,
                (string) $this->revocationTombstoneTtl,
            ],
            3,
        );
    }

    public function revokeAllForUser(string $userId): void
    {
        $this->redis->eval(
            self::LUA_REVOKE_ALL,
            [
                self::USER_TOKENS_PREFIX . $userId,
                self::TOKEN_PREFIX,
                self::REVOKED_PREFIX,
                (string) $this->revocationTombstoneTtl,
            ],
            1,
        );
    }
}
