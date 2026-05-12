<?php

declare(strict_types=1);

namespace Vortos\Observability\Telemetry;

final readonly class MetricLabelValue
{
    private function __construct(
        public MetricLabel $label,
        public string $value,
    ) {}

    public static function of(MetricLabel $label, string|\Stringable|int|float|bool|null $value): self
    {
        return new self($label, TelemetryLabels::safe((string) ($value ?? 'none')));
    }

    public static function result(MetricResult $result): self
    {
        return new self(MetricLabel::Result, $result->value);
    }

    public static function operation(MetricOperation $operation): self
    {
        return new self(MetricLabel::Operation, $operation->value);
    }
}
