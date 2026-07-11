<?php

declare(strict_types=1);

namespace Vortos\Audit\Ingestion\Idempotency;

/**
 * Process-local guard: dedupes within a single consumer process. Sufficient when only
 * one consumer instance runs; use the Redis guard for multi-instance deployments. The
 * DB primary key remains the cross-process authority either way.
 */
final class InMemoryIdempotencyGuard implements IdempotencyGuardInterface
{
    /** @var array<string, true> */
    private array $claimed = [];

    public function claim(string $key, int $ttlSeconds): bool
    {
        if (isset($this->claimed[$key])) {
            return false;
        }
        $this->claimed[$key] = true;

        return true;
    }

    public function release(string $key): void
    {
        unset($this->claimed[$key]);
    }
}
