<?php

declare(strict_types=1);

namespace Vortos\Metrics\Adapter;

use Vortos\Metrics\Contract\CounterInterface;
use Vortos\Metrics\Contract\GaugeInterface;
use Vortos\Metrics\Contract\HistogramInterface;
use Vortos\Metrics\Contract\MetricsInterface;
use Vortos\Metrics\Definition\MetricDefinitionRegistry;
use Vortos\Metrics\Definition\MetricType;
use Vortos\Metrics\Instrument\NoOpCounter;
use Vortos\Metrics\Instrument\NoOpGauge;
use Vortos\Metrics\Instrument\NoOpHistogram;

/**
 * No-operation metrics adapter. Default MetricsInterface implementation.
 *
 * All methods return shared singleton no-op instruments — zero allocations per call.
 * Replace with PrometheusMetrics or StatsDMetrics via VortosMetricsConfig.
 */
final class NoOpMetrics implements MetricsInterface
{
    private static CounterInterface $counter;
    private static GaugeInterface $gauge;
    private static HistogramInterface $histogram;

    public function __construct(private readonly MetricDefinitionRegistry $definitions) {}

    public function counter(string $name, array $labels = []): CounterInterface
    {
        $definition = $this->definitions->requireType($name, MetricType::Counter);
        $this->definitions->validateLabels($definition, $labels);

        return self::$counter ??= new NoOpCounter();
    }

    public function gauge(string $name, array $labels = []): GaugeInterface
    {
        $definition = $this->definitions->requireType($name, MetricType::Gauge);
        $this->definitions->validateLabels($definition, $labels);

        return self::$gauge ??= new NoOpGauge();
    }

    public function histogram(string $name, array $labels = []): HistogramInterface
    {
        $definition = $this->definitions->requireType($name, MetricType::Histogram);
        $this->definitions->validateLabels($definition, $labels);

        return self::$histogram ??= new NoOpHistogram();
    }
}
