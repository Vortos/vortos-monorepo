<?php

declare(strict_types=1);

namespace Vortos\Metrics\Instrument;

use Prometheus\Gauge;
use Vortos\Metrics\Contract\GaugeInterface;

final class PrometheusGauge implements GaugeInterface
{
    /** @param array<string> $labelValues */
    public function __construct(
        private readonly Gauge $gauge,
        private readonly array $labelValues,
    ) {}

    public function set(float $value): void
    {
        $this->gauge->set($value, $this->labelValues);
    }

    public function increment(float $by = 1.0): void
    {
        $this->gauge->incBy($by, $this->labelValues);
    }

    public function decrement(float $by = 1.0): void
    {
        $this->gauge->decBy($by, $this->labelValues);
    }
}
