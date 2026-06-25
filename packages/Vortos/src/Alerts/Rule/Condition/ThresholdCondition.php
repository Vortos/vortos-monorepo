<?php

declare(strict_types=1);

namespace Vortos\Alerts\Rule\Condition;

use InvalidArgumentException;

/** Used by `error_rate` / `p95_latency` / `queue_lag` — a single checked threshold, never a free-floating float. */
final readonly class ThresholdCondition implements AlertConditionInterface
{
    public function __construct(
        public ThresholdOperator $op,
        public float $value,
    ) {
        if (!is_finite($value)) {
            throw new InvalidArgumentException('ThresholdCondition value must be finite.');
        }
        if ($value < 0.0) {
            throw new InvalidArgumentException('ThresholdCondition value must be >= 0.');
        }
    }

    public function fires(float $observed): bool
    {
        return $this->op->compare($observed, $this->value);
    }
}
