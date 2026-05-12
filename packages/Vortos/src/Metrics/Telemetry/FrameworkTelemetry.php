<?php

declare(strict_types=1);

namespace Vortos\Metrics\Telemetry;

use Vortos\Metrics\Contract\MetricsInterface;
use Vortos\Observability\Config\ObservabilityModule;
use Vortos\Observability\Telemetry\FrameworkMetric;
use Vortos\Observability\Telemetry\FrameworkMetricLabels;

final class FrameworkTelemetry
{
    /** @var array<string, true> */
    private array $disabledModules;

    /** @param list<string|ObservabilityModule> $disabledModules */
    public function __construct(
        private readonly ?MetricsInterface $metrics = null,
        array $disabledModules = [],
    ) {
        $this->disabledModules = [];
        foreach ($disabledModules as $module) {
            $value = $module instanceof ObservabilityModule ? $module->value : ObservabilityModule::fromLegacy((string) $module)->value;
            $this->disabledModules[$value] = true;
        }
    }

    public function increment(
        ObservabilityModule $module,
        FrameworkMetric $metric,
        FrameworkMetricLabels $labels,
        float $by = 1.0,
    ): void {
        if ($this->metrics === null || isset($this->disabledModules[$module->value])) {
            return;
        }

        $this->metrics->counter($metric->value, $labels->toArray())->increment($by);
    }

    public function observe(
        ObservabilityModule $module,
        FrameworkMetric $metric,
        FrameworkMetricLabels $labels,
        float $value,
    ): void {
        if ($this->metrics === null || isset($this->disabledModules[$module->value])) {
            return;
        }

        $this->metrics->histogram($metric->value, $labels->toArray())->observe($value);
    }

    public function setGauge(
        ObservabilityModule $module,
        FrameworkMetric $metric,
        FrameworkMetricLabels $labels,
        float $value,
    ): void {
        if ($this->metrics === null || isset($this->disabledModules[$module->value])) {
            return;
        }

        $this->metrics->gauge($metric->value, $labels->toArray())->set($value);
    }
}
