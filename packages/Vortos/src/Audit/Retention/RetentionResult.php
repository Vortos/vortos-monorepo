<?php

declare(strict_types=1);

namespace Vortos\Audit\Retention;

/**
 * Summary of one sweep: how many records were archived+purged per chain (or would be,
 * in dry-run).
 */
final class RetentionResult
{
    /** @var array<string, int> chainKey => records archived */
    private array $archived = [];

    public function record(string $chainKey, int $count): void
    {
        $this->archived[$chainKey] = ($this->archived[$chainKey] ?? 0) + $count;
    }

    /** @return array<string, int> */
    public function perChain(): array
    {
        return $this->archived;
    }

    public function total(): int
    {
        return array_sum($this->archived);
    }
}
