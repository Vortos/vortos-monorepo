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

    public function __construct(private \Redis $redis) {}

    public function store(string $jti, string $userId, int $expiresAt): void
    {
        $ttl = $expiresAt - time();

        if ($ttl <= 0) {
            return;
        }

        $this->redis->setex(self::TOKEN_PREFIX . $jti, $ttl, $userId);

        // Track JTI under user for revokeAllForUser()
        $userKey = self::USER_TOKENS_PREFIX . $userId;
        $this->redis->sAdd($userKey, $jti);

        // Extend user-set TTL to cover this token plus a buffer — never shrink an existing TTL.
        // Using a fixed 86400*8 broke when refreshTokenTtl was configured longer than 8 days.
        $requiredTtl = $ttl + 3600;
        $existingTtl = $this->redis->ttl($userKey);
        if ($existingTtl < $requiredTtl) {
            $this->redis->expire($userKey, $requiredTtl);
        }
    }

    public function isValid(string $jti): bool
    {
        return (bool) $this->redis->exists(self::TOKEN_PREFIX . $jti);
    }

    public function revoke(string $jti): void
    {
        $userId = $this->redis->get(self::TOKEN_PREFIX . $jti);
        $this->redis->del(self::TOKEN_PREFIX . $jti);

        if ($userId !== false) {
            $this->redis->sRem(self::USER_TOKENS_PREFIX . $userId, $jti);
        }
    }

    public function revokeAllForUser(string $userId): void
    {
        $userKey = self::USER_TOKENS_PREFIX . $userId;
        $jtis = $this->redis->sMembers($userKey);

        if (!empty($jtis)) {
            $keys = array_map(fn(string $jti) => self::TOKEN_PREFIX . $jti, $jtis);
            $this->redis->del(...$keys);
        }

        $this->redis->del($userKey);
    }
}
