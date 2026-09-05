<?php

declare(strict_types=1);

namespace Vortos\Metrics\Instrument;

use OpenTelemetry\API\Metrics\CounterInterface as OTelCounterInterface;
use Vortos\Metrics\Contract\CounterInterface;

final readonly class OpenTelemetryCounter implements CounterInterface
{
    /** @param array<string, string> $labels */
    public function __construct(
        private OTelCounterInterface $counter,
        private array $labels,
    ) {}

    /**
     * Records a counter delta.
     *
     * ZERO IS A VALID MEASUREMENT and is recorded. Only a negative delta is rejected, because a
     * counter is monotonic and OTLP has no representation for a decrease.
     *
     * The guard used to be `$by <= 0.0`, which silently swallowed zero as well — and that was a
     * production incident, not a nuance. `MetricsInterface::counter()` creates the underlying OTLP
     * instrument on the way in, BEFORE this method runs. So `counter(...)->increment(0)` left an
     * instrument that had never recorded anything, and the SDK exported it with an empty
     * dataPoints list. Grafana Cloud / Mimir rejects such a payload with
     * `400 otlp parse error: empty data points` and discards THE ENTIRE REQUEST — on one
     * deployment that was ~297 unrelated datapoints lost per rejection and roughly 9% of all
     * metrics, for months, while the pipeline otherwise reported itself healthy.
     *
     * Callers legitimately want to record a zero: a counter that only materialises when something
     * is wrong cannot be alerted on for having stopped. Letting the zero through records a real
     * data point and makes that intent work, instead of quietly poisoning every batch it rides in.
     */
    public function increment(float $by = 1.0): void
    {
        if ($by < 0.0) {
            return;
        }

        $this->counter->add($by, $this->labels);
    }
}
