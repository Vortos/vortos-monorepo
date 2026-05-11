<?php

declare(strict_types=1);

namespace Vortos\Metrics\Definition;

enum MetricType: string
{
    case Counter = 'counter';
    case Gauge = 'gauge';
    case Histogram = 'histogram';
}
