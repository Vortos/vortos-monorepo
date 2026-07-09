<?php

declare(strict_types=1);

namespace Vortos\Deploy\Cutover\Lock;

/**
 * A short-lived mutex serialising edge cutovers for one environment.
 *
 * Two overlapping deploys that both adapt-merge, /load, and write the durable boot file can tear the
 * live config or leave the on-disk file mismatched with the live route. This lock makes the cutover
 * critical section single-writer per environment. It is a control-plane concern (Redis by default);
 * an infra-less single node uses the no-op {@see NullEdgeCutoverLock} because CI already serialises
 * that box's deploys.
 */
interface EdgeCutoverLockInterface
{
    /**
     * Try to acquire the cutover lock for the environment. Returns an opaque owner token on success,
     * or null if the lock is already held (a concurrent cutover is in flight). The lock auto-expires
     * after $ttlSeconds so a crashed holder cannot wedge the environment forever.
     */
    public function acquire(string $env, int $ttlSeconds): ?string;

    /** Release the lock only if $token still owns it (a lease that expired and was re-taken is left alone). */
    public function release(string $env, string $token): void;
}
