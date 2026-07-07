<?php

declare(strict_types=1);

namespace Vortos\Metrics\Config;

/**
 * OTLP metric aggregation temporality.
 *
 * Controls the `aggregationTemporality` the OpenTelemetry exporter stamps on every
 * exported data point.
 *
 *  - {@see self::Cumulative} — running totals from a fixed start time. The portable,
 *    Prometheus-compatible default. Required by Grafana Cloud / Mimir and the Prometheus
 *    OTLP receiver (delta is rejected with HTTP 400 "invalid temporality and type
 *    combination"); also accepted by New Relic and Datadog. Under a per-request flush
 *    worker model it is self-healing: each flush re-sends the cumulative total, so a
 *    dropped export is recovered by the next request rather than lost.
 *  - {@see self::Delta} — per-interval deltas. Only for delta-native backends (or a
 *    collector configured to convert delta→cumulative). Opt-in.
 *
 * Default is {@see self::Cumulative}; switch via
 * {@see \Vortos\Metrics\DependencyInjection\VortosMetricsConfig::metricsTemporality()}.
 */
enum MetricTemporality: string
{
    case Cumulative = 'cumulative';
    case Delta      = 'delta';
}
