<?php

declare(strict_types=1);

namespace Vortos\Metrics\Adapter;

use Vortos\Metrics\Contract\CounterInterface;
use Vortos\Metrics\Contract\GaugeInterface;
use Vortos\Metrics\Contract\HistogramInterface;
use Vortos\Metrics\Contract\MetricsInterface;
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

    public function counter(string $name, array $labels = []): CounterInterface
    {
        return self::$counter ??= new NoOpCounter();
    }

    public function gauge(string $name, array $labels = []): GaugeInterface
    {
        return self::$gauge ??= new NoOpGauge();
    }

    public function histogram(string $name, array $buckets = [], array $labels = []): HistogramInterface
    {
        return self::$histogram ??= new NoOpHistogram();
    }
}
