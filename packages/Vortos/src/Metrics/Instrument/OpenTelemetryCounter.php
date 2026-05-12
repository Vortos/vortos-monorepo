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

    public function increment(float $by = 1.0): void
    {
        if ($by <= 0.0) {
            return;
        }

        $this->counter->add($by, $this->labels);
    }
}
