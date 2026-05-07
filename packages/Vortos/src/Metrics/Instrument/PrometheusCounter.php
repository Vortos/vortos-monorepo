<?php

declare(strict_types=1);

namespace Vortos\Metrics\Instrument;

use Prometheus\Counter;
use Vortos\Metrics\Contract\CounterInterface;

final class PrometheusCounter implements CounterInterface
{
    /** @param array<string> $labelValues */
    public function __construct(
        private readonly Counter $counter,
        private readonly array $labelValues,
    ) {}

    public function increment(float $by = 1.0): void
    {
        $this->counter->incBy($by, $this->labelValues);
    }
}
