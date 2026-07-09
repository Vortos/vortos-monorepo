<?php

declare(strict_types=1);

namespace Vortos\Deploy\Cutover\Lock;

/**
 * Redis-backed cutover mutex: SET key token NX EX ttl to acquire, a token-checked delete to release.
 *
 * The token guards against releasing a lock a slow holder no longer owns (its lease expired and
 * another deploy re-took it): release deletes only when the stored token still matches. When Redis is
 * unavailable the lock degrades to "acquire fails" so the cutover fails closed rather than running
 * unguarded — the deploy retries rather than racing.
 */
final class RedisEdgeCutoverLock implements EdgeCutoverLockInterface
{
    private const KEY_PREFIX = 'vortos:edge:cutover:lock:';

    public function __construct(
        private readonly ?\Redis $redis = null,
    ) {}

    public function acquire(string $env, int $ttlSeconds): ?string
    {
        if ($this->redis === null) {
            return null;
        }

        $token = bin2hex(random_bytes(16));

        try {
            $ok = $this->redis->set(self::KEY_PREFIX . $env, $token, ['NX', 'EX' => max(1, $ttlSeconds)]);
        } catch (\Throwable) {
            return null;
        }

        return $ok === true ? $token : null;
    }

    public function release(string $env, string $token): void
    {
        if ($this->redis === null) {
            return;
        }

        // Compare-and-delete so we never release a lease another holder now owns.
        $script = <<<'LUA'
        if redis.call("get", KEYS[1]) == ARGV[1] then
            return redis.call("del", KEYS[1])
        end
        return 0
        LUA;

        try {
            $this->redis->eval($script, [self::KEY_PREFIX . $env, $token], 1);
        } catch (\Throwable) {
            // Best-effort release; the lease TTL will reclaim the lock regardless.
        }
    }
}
