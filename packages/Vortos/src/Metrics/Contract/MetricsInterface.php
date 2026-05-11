<?php

declare(strict_types=1);

namespace Vortos\Metrics\Contract;

/**
 * Framework metrics abstraction.
 *
 * The three metric types map to the industry standard:
 *   Counter   — monotonically increasing value (requests, errors, events)
 *   Gauge     — arbitrary value that can go up or down (queue depth, active connections)
 *   Histogram — distribution of values with configurable buckets (response time, query duration)
 *
 * Default implementation is NoOpMetrics — zero overhead, no dependencies.
 * Replace with PrometheusMetrics or StatsDMetrics via VortosMetricsConfig.
 *
 * ## Metric definitions
 *
 *   Every metric must be declared before observation. The definition owns the
 *   type, help text, allowed label names, and histogram buckets. This keeps
 *   Prometheus HELP output complete and prevents accidental label drift.
 *
 * ## Label conventions (Prometheus-style)
 *
 *   Labels must be known at instrument creation time.
 *   Keys are snake_case strings. Values are strings.
 *   High-cardinality labels (user IDs, request IDs) must NOT be used —
 *   they cause metric cardinality explosion in Prometheus.
 *
 * ## Naming convention
 *
 *   {namespace}_{subsystem}_{name}_{unit}
 *   e.g. vortos_http_requests_total, vortos_db_query_duration_ms
 *
 * ## Thread safety
 *
 *   Implementations must be safe to call from multiple FrankenPHP worker fibers.
 *   PrometheusMetrics with Redis storage is multi-process safe.
 *   PrometheusMetrics with in-memory storage is NOT safe across workers.
 */
interface MetricsInterface
{
    /**
     * Return a Counter instrument with the given name and label values.
     *
     * Counters only go up. Call increment() on the returned instrument.
     * Labels must exactly match the metric definition.
     */
    public function counter(string $name, array $labels = []): CounterInterface;

    /**
     * Return a Gauge instrument with the given name and label values.
     *
     * Gauges can go up and down. Call set(), increment(), or decrement().
     */
    public function gauge(string $name, array $labels = []): GaugeInterface;

    /**
     * Return a Histogram instrument with the given name and label values.
     *
     * Histograms track value distributions. Call observe() with measured values.
     * Buckets are read from the metric definition.
     */
    public function histogram(string $name, array $labels = []): HistogramInterface;
}
