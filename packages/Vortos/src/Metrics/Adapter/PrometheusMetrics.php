<?php

declare(strict_types=1);

namespace Vortos\Metrics\Adapter;

use Prometheus\CollectorRegistry;
use Vortos\Metrics\Contract\CounterInterface;
use Vortos\Metrics\Contract\GaugeInterface;
use Vortos\Metrics\Contract\HistogramInterface;
use Vortos\Metrics\Contract\MetricsInterface;
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
 * ## Label names vs label values
 *
 * MetricsInterface::counter(name, labelValues) is called at observation time.
 * The label NAMES must be pre-declared when the instrument is first registered.
 * This adapter derives label names from the label values keys and registers
 * instruments lazily on first use — the label keys from the first call become
 * the canonical label names for that metric.
 *
 * ## Metric registration idempotency
 *
 * CollectorRegistry::getOrRegister*() is idempotent — safe to call repeatedly.
 * Calling with different label names for the same metric name throws — the caller
 * must always use the same label keys for a given metric name.
 *
 * ## Default histogram buckets
 *
 * [5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000] (milliseconds)
 * Suitable for HTTP request durations. Override per histogram call.
 */
final class PrometheusMetrics implements MetricsInterface
{
    private const DEFAULT_BUCKETS = [5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000];

    public function __construct(
        private readonly CollectorRegistry $registry,
        private readonly string $namespace = 'vortos',
    ) {}

    public function counter(string $name, array $labels = []): CounterInterface
    {
        $labelNames  = array_keys($labels);
        $labelValues = array_values($labels);

        $counter = $this->registry->getOrRegisterCounter(
            $this->namespace,
            $name,
            '',
            $labelNames,
        );

        return new PrometheusCounter($counter, array_map('strval', $labelValues));
    }

    public function gauge(string $name, array $labels = []): GaugeInterface
    {
        $labelNames  = array_keys($labels);
        $labelValues = array_values($labels);

        $gauge = $this->registry->getOrRegisterGauge(
            $this->namespace,
            $name,
            '',
            $labelNames,
        );

        return new PrometheusGauge($gauge, array_map('strval', $labelValues));
    }

    public function histogram(string $name, array $buckets = [], array $labels = []): HistogramInterface
    {
        $labelNames  = array_keys($labels);
        $labelValues = array_values($labels);

        $histogram = $this->registry->getOrRegisterHistogram(
            $this->namespace,
            $name,
            '',
            $labelNames,
            empty($buckets) ? self::DEFAULT_BUCKETS : $buckets,
        );

        return new PrometheusHistogram($histogram, array_map('strval', $labelValues));
    }
}
