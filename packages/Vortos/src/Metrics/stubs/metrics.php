<?php

declare(strict_types=1);

use Vortos\Metrics\Config\MetricsAdapter;
use Vortos\Metrics\Config\MetricsModule;
use Vortos\Metrics\DependencyInjection\VortosMetricsConfig;

// Default adapter is NoOp — zero overhead, no configuration required.
// Switch to Prometheus or StatsD when you need actual metrics.
//
// For per-environment overrides create config/{env}/metrics.php.

return static function (VortosMetricsConfig $config): void {
    $config
        // Choose a metrics backend.
        //
        // MetricsAdapter::NoOp       — silently discards all metrics (default, zero cost)
        // MetricsAdapter::Prometheus — exposes a /metrics scrape endpoint
        // MetricsAdapter::StatsD     — pushes metrics to a StatsD agent
        ->adapter(MetricsAdapter::NoOp)

        // Prefix applied to all metric names: {namespace}_{metric_name}.
        ->namespace('app')
    ;

    // Disable auto-instrumentation for specific modules.
    // Useful for high-cardinality modules (Cache, Persistence) in prod.
    //
    // $config->disableModule(
    //     MetricsModule::Cache,
    //     MetricsModule::Persistence,
    // );

    // Prometheus configuration — only relevant when adapter = Prometheus.
    //
    // Storage options:
    //   prometheusStorageInMemory() — default, single-process only (dev)
    //   prometheusStorageRedis()    — multi-process safe (required for FrankenPHP prod)
    //   prometheusStorageApc()      — PHP-FPM multi-process safe, requires apcu extension
    //
    // $config
    //     ->adapter(MetricsAdapter::Prometheus)
    //     ->prometheusStorageRedis(prefix: 'metrics:')          // reuses vortos-cache Redis
    //     ->prometheusEndpoint('/metrics')
    //     ->prometheusEndpointToken($_ENV['METRICS_TOKEN'] ?? '') // always set a token in prod
    // ;

    // StatsD configuration — only relevant when adapter = StatsD.
    //
    // $config
    //     ->adapter(MetricsAdapter::StatsD)
    //     ->statsDHost($_ENV['STATSD_HOST'] ?? '127.0.0.1')
    //     ->statsDPort(8125)
    //     ->statsDSampleRate(1.0) // 1.0 = send every metric; lower to reduce traffic
    // ;
};
