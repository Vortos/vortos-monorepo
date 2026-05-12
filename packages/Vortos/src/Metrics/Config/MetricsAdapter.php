<?php

declare(strict_types=1);

namespace Vortos\Metrics\Config;

enum MetricsAdapter: string
{
    case NoOp       = 'noop';
    case Prometheus = 'prometheus';
    case StatsD     = 'statsd';
    case OpenTelemetry = 'opentelemetry';
}
