<?php

declare(strict_types=1);

namespace Vortos\Metrics\Instrument;

use OpenTelemetry\API\Metrics\GaugeInterface as OTelGaugeInterface;
use Vortos\Metrics\Contract\GaugeInterface;

final readonly class OpenTelemetryGauge implements GaugeInterface
{
    /** @param array<string, string> $labels */
    public function __construct(
        private OTelGaugeInterface $gauge,
        private array $labels,
        private \Closure $setValue,
        private \Closure $changeValue,
    ) {}

    public function set(float $value): void
    {
        ($this->setValue)($value);
        $this->gauge->record($value, $this->labels);
    }

    public function increment(float $by = 1.0): void
    {
        $this->set(($this->changeValue)($by));
    }

    public function decrement(float $by = 1.0): void
    {
        $this->set(($this->changeValue)(-$by));
    }
}
