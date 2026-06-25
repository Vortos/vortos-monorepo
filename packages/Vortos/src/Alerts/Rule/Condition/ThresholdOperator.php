<?php

declare(strict_types=1);

namespace Vortos\Alerts\Rule\Condition;

enum ThresholdOperator: string
{
    case GreaterThan = 'gt';
    case GreaterThanOrEqual = 'gte';
    case LessThan = 'lt';
    case LessThanOrEqual = 'lte';

    public function compare(float $observed, float $threshold): bool
    {
        return match ($this) {
            self::GreaterThan => $observed > $threshold,
            self::GreaterThanOrEqual => $observed >= $threshold,
            self::LessThan => $observed < $threshold,
            self::LessThanOrEqual => $observed <= $threshold,
        };
    }
}
