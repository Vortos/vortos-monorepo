<?php

declare(strict_types=1);

namespace Vortos\Metrics\Instrument;

use Prometheus\Histogram;
use Vortos\Metrics\Contract\HistogramInterface;

final class PrometheusHistogram implements HistogramInterface
{
    /** @param array<string> $labelValues */
    public function __construct(
        private readonly Histogram $histogram,
        private readonly array $labelValues,
    ) {}

    public function observe(float $value): void
    {
        $this->histogram->observe($value, $this->labelValues);
    }
}
