<?php

declare(strict_types=1);

namespace Vortos\Alerts\Rule\Sample;

/** Observed value for `error_rate` / `p95_latency` / `queue_lag`. */
final readonly class ThresholdSample implements SampleInterface
{
    public function __construct(public float $value)
    {
    }
}
