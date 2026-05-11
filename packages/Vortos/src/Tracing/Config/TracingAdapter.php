<?php

declare(strict_types=1);

namespace Vortos\Tracing\Config;

enum TracingAdapter: string
{
    case NoOp = 'noop';
    case OpenTelemetry = 'opentelemetry';
}
