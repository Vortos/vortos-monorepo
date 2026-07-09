<?php

declare(strict_types=1);

namespace Vortos\Deploy\Cutover\Lock;

/**
 * No-op cutover lock for a single infra-less edge node, where CI already serialises deploys so there
 * is no concurrent writer to guard against. Always acquires; release is a no-op.
 */
final class NullEdgeCutoverLock implements EdgeCutoverLockInterface
{
    public function acquire(string $env, int $ttlSeconds): ?string
    {
        return 'null-lock';
    }

    public function release(string $env, string $token): void
    {
    }
}
