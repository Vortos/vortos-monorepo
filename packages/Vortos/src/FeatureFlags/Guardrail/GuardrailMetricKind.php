<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Guardrail;

enum GuardrailMetricKind: string
{
    case ErrorRate        = 'error_rate';
    case LatencyP99       = 'latency_p99';
    case LatencyP50       = 'latency_p50';
    case Custom           = 'custom';
    case ExposureRateDrop = 'exposure_rate_drop';
}
