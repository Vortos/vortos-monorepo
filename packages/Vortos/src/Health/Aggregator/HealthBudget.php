<?php

declare(strict_types=1);

namespace Vortos\Health\Aggregator;

final readonly class HealthBudget
{
    public function __construct(
        public int $perProbeDeadlineMs = 3000,
        public int $overallBudgetMs = 10000,
        public int $readyCacheTtlMs = 1000,
    ) {
        if ($this->perProbeDeadlineMs < 1) {
            throw new \InvalidArgumentException('perProbeDeadlineMs must be >= 1.');
        }

        if ($this->overallBudgetMs < 1) {
            throw new \InvalidArgumentException('overallBudgetMs must be >= 1.');
        }

        if ($this->perProbeDeadlineMs > $this->overallBudgetMs) {
            throw new \InvalidArgumentException('perProbeDeadlineMs must be <= overallBudgetMs.');
        }

        if ($this->readyCacheTtlMs < 0) {
            throw new \InvalidArgumentException('readyCacheTtlMs must be >= 0.');
        }
    }
}
