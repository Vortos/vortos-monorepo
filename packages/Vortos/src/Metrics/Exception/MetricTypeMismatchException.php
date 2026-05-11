<?php

declare(strict_types=1);

namespace Vortos\Metrics\Exception;

use Vortos\Metrics\Definition\MetricType;

final class MetricTypeMismatchException extends \InvalidArgumentException
{
    public function __construct(string $name, MetricType $expected, MetricType $actual)
    {
        parent::__construct(sprintf(
            'Metric "%s" is defined as %s, but was requested as %s.',
            $name,
            $actual->value,
            $expected->value,
        ));
    }
}
