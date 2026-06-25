<?php

declare(strict_types=1);

namespace Vortos\Deploy\Canary;

final readonly class CanaryMetricSpec
{
    public function __construct(
        public CanarySloRef $slo,
        public CanaryComparator $comparator,
        /** Fractional tolerance: staged ≤ stable × (1 + tolerance) for lower-is-better. */
        public float $tolerance,
        /** true = lower is better (error-rate, latency); false = higher is better (throughput). */
        public bool $lowerIsBetter,
    ) {
        if ($tolerance < 0.0 || $tolerance > 1.0) {
            throw new \InvalidArgumentException(sprintf('Tolerance must be in [0,1], got %s.', $tolerance));
        }
    }

    public static function errorRate(CanarySloRef $slo, float $tolerance = 0.10): self
    {
        return new self($slo, CanaryComparator::RelativeToBaseline, $tolerance, true);
    }

    public static function latencyP99(CanarySloRef $slo, float $tolerance = 0.15): self
    {
        return new self($slo, CanaryComparator::RelativeToBaseline, $tolerance, true);
    }

    public static function burnRate(CanarySloRef $slo, float $tolerance = 0.05): self
    {
        return new self($slo, CanaryComparator::RelativeToBaseline, $tolerance, true);
    }

    public static function absolute(CanarySloRef $slo, float $tolerance = 0.05, bool $lowerIsBetter = true): self
    {
        return new self($slo, CanaryComparator::AbsoluteThreshold, $tolerance, $lowerIsBetter);
    }
}
