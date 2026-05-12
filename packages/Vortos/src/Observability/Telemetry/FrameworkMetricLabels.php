<?php

declare(strict_types=1);

namespace Vortos\Observability\Telemetry;

final readonly class FrameworkMetricLabels
{
    /** @var array<string, string> */
    private array $labels;

    private function __construct(MetricLabelValue ...$values)
    {
        $labels = [];
        foreach ($values as $value) {
            $labels[$value->label->value] = $value->value;
        }

        $this->labels = $labels;
    }

    public static function of(MetricLabelValue ...$values): self
    {
        return new self(...$values);
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return $this->labels;
    }
}
