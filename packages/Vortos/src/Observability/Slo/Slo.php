<?php

declare(strict_types=1);

namespace Vortos\Observability\Slo;

use InvalidArgumentException;

/**
 * A declared, validated SLO definition (Block 16, §3.4) — name, objective, the
 * window it is measured over, and the indicator (metric/query reference) backing it.
 * Illegal states are unrepresentable: validation happens at construction, never at
 * runtime (Golden Rule discipline).
 */
final readonly class Slo
{
    public function __construct(
        public string $name,
        public float $objective,
        public SloWindow $window,
        public string $indicatorRef,
    ) {
        if ($name === '') {
            throw new InvalidArgumentException('Slo name must not be empty.');
        }
        if ($objective <= 0.0 || $objective >= 1.0) {
            throw new InvalidArgumentException(sprintf('Slo objective must be in (0, 1), got %s.', $objective));
        }
        if ($indicatorRef === '') {
            throw new InvalidArgumentException('Slo indicatorRef must not be empty.');
        }
    }

    public function errorBudget(): ErrorBudget
    {
        return new ErrorBudget($this);
    }
}
