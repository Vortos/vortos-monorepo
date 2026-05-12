<?php

declare(strict_types=1);

namespace Vortos\Metrics\Instrument;

use OpenTelemetry\API\Metrics\HistogramInterface as OTelHistogramInterface;
use Vortos\Metrics\Contract\HistogramInterface;

final readonly class OpenTelemetryHistogram implements HistogramInterface
{
    /** @param array<string, string> $labels */
    public function __construct(
        private OTelHistogramInterface $histogram,
        private array $labels,
    ) {}

    public function observe(float $value): void
    {
        if ($value < 0.0) {
            return;
        }

        $this->histogram->record($value, $this->labels);
    }
}
