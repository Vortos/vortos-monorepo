<?php

declare(strict_types=1);

namespace Vortos\Audit\Retention;

use Vortos\Audit\Enum\Scope;

/**
 * Resolves how long a chain is retained in the hot table before archive+purge.
 *
 * Retention is per-scope with per-tenant overrides — a single window per chain (not
 * per-sensitivity), which keeps purge a clean contiguous-prefix operation. Longer
 * retention for sensitive material is expressed by choosing a longer window, not by
 * carving holes in a chain. A tenant override of 0 (or negative) means "never purge".
 */
final class AuditRetentionPolicy
{
    /**
     * @param array<string, int> $tenantOverrides tenantId => days (0/negative = never)
     */
    public function __construct(
        private readonly int   $platformDays,
        private readonly int   $tenantDefaultDays,
        private readonly array $tenantOverrides = [],
    ) {}

    /**
     * The cutoff instant for a chain: records older than this are eligible for archive+
     * purge. Returns null when retention is disabled for that chain (never purge).
     */
    public function cutoffFor(string $chainKey, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $days = $this->daysFor($chainKey);

        return $days > 0 ? $now->modify("-{$days} days") : null;
    }

    private function daysFor(string $chainKey): int
    {
        if ($chainKey === Scope::Platform->value) {
            return $this->platformDays;
        }

        // 'tenant:{id}'
        $tenantId = str_starts_with($chainKey, 'tenant:') ? substr($chainKey, 7) : $chainKey;

        return $this->tenantOverrides[$tenantId] ?? $this->tenantDefaultDays;
    }
}
