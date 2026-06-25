<?php

declare(strict_types=1);

namespace Vortos\Alerts\Rule\Sample;

/** Observed result for `health_probe_failing`, off the readiness path. */
final readonly class HealthProbeSample implements SampleInterface
{
    public function __construct(
        public bool $failing,
        public string $probeName,
        public string $detail = '',
    ) {}
}
