<?php

declare(strict_types=1);

namespace Vortos\Deploy\Canary;

final readonly class MetricEvaluation
{
    public function __construct(
        public string $sloName,
        public CanaryComparator $comparator,
        public float $stagedValue,
        public ?float $stableValue,
        public bool $breached,
        public string $reason,
    ) {}
}
