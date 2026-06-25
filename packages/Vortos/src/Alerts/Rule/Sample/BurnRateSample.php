<?php

declare(strict_types=1);

namespace Vortos\Alerts\Rule\Sample;

/** Observed dual-window burn rate for `slo_burn`, read off `ErrorBudget::burnRate()`. */
final readonly class BurnRateSample implements SampleInterface
{
    public function __construct(
        public float $fastBurnRate,
        public float $slowBurnRate,
    ) {}
}
