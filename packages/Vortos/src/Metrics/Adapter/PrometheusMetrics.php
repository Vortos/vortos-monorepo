<?php

declare(strict_types=1);

namespace Vortos\Metrics\Adapter;

use Prometheus\CollectorRegistry;
use Vortos\Metrics\Contract\CounterInterface;
use Vortos\Metrics\Contract\GaugeInterface;
use Vortos\Metrics\Contract\HistogramInterface;
use Vortos\Metrics\Contract\MetricsInterface;
use Vortos\Metrics\Definition\MetricDefinitionRegistry;
use Vortos\Metrics\Definition\MetricType;
use Vortos\Metrics\Instrument\PrometheusCounter;
use Vortos\Metrics\Instrument\PrometheusGauge;
use Vortos\Metrics\Instrument\PrometheusHistogram;

/**
 * Prometheus metrics adapter via promphp/prometheus_client_php.
 *
 * Registers metrics with a CollectorRegistry backed by the configured storage.
 * Storage options (configured in VortosMetricsConfig):
 *   - InMemory   — single-process, dev only
 *   - Redis      — multi-process safe, required for FrankenPHP worker mode in prod
 *   - APC        — shared memory, requires apcu extension, PHP-FPM only
 *
 * ## Metric definitions
 *
 * All metrics are declared before observation. Definitions provide the help
 * text, allowed label names, and histogram buckets. Observation validates the
 * label set and orders label values by the definition before handing them to
 * Prometheus.
 */
final class PrometheusMetrics implements MetricsInterface
{
    public function __construct(
        private readonly CollectorRegistry $registry,
        private readonly MetricDefinitionRegistry $definitions,
        private readonly string $namespace = 'vortos',
    ) {}

    public function counter(string $name, array $labels = []): CounterInterface
    {
        $definition = $this->definitions->requireType($name, MetricType::Counter);
        $orderedLabels = $this->definitions->validateLabels($definition, $labels);

        $counter = $this->registry->getOrRegisterCounter(
            $this->namespace,
            $name,
            $definition->help,
            $definition->labelNames,
        );

        return new PrometheusCounter($counter, array_values($orderedLabels));
    }

    public function gauge(string $name, array $labels = []): GaugeInterface
    {
        $definition = $this->definitions->requireType($name, MetricType::Gauge);
        $orderedLabels = $this->definitions->validateLabels($definition, $labels);

        $gauge = $this->registry->getOrRegisterGauge(
            $this->namespace,
            $name,
            $definition->help,
            $definition->labelNames,
        );

        return new PrometheusGauge($gauge, array_values($orderedLabels));
    }

    public function histogram(string $name, array $labels = []): HistogramInterface
    {
        $definition = $this->definitions->requireType($name, MetricType::Histogram);
        $orderedLabels = $this->definitions->validateLabels($definition, $labels);

        $histogram = $this->registry->getOrRegisterHistogram(
            $this->namespace,
            $name,
            $definition->help,
            $definition->labelNames,
            $definition->buckets,
        );

        return new PrometheusHistogram($histogram, array_values($orderedLabels));
    }
}
