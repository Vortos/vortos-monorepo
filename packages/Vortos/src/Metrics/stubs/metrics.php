<?php

declare(strict_types=1);

use Vortos\Metrics\Config\MetricsAdapter;
use Vortos\Metrics\DependencyInjection\VortosMetricsConfig;
use Vortos\Observability\Config\ObservabilityModule;

// Default adapter is NoOp — zero overhead, no configuration required.
// Switch to Prometheus, StatsD, or OpenTelemetry OTLP when you need actual metrics.
//
// For per-environment overrides create config/{env}/metrics.php.

return static function (VortosMetricsConfig $config): void {
    $config
        // Choose a metrics backend.
        //
        // MetricsAdapter::NoOp       — silently discards all metrics (default, zero cost)
        // MetricsAdapter::Prometheus — exposes a /metrics scrape endpoint
        // MetricsAdapter::StatsD     — pushes metrics to a StatsD agent
        // MetricsAdapter::OpenTelemetry — pushes OTLP metrics to an OTel Collector/vendor
        ->adapter(MetricsAdapter::NoOp)

        // Prefix applied to all metric names: {namespace}_{metric_name}.
        ->namespace('app')
    ;

    // Disable auto-instrumentation for specific modules.
    // Useful when you want to replace a framework module metric with your own
    // lower-cardinality instrumentation.
    //
    // $config->disableModule(
    //     ObservabilityModule::Cache,
    //     ObservabilityModule::Persistence,
    //     ObservabilityModule::Auth,
    // );

    // Application metrics must be declared before they are recorded.
    // The definition owns the Prometheus HELP text, allowed label names, and
    // histogram buckets. Runtime calls must use exactly the same labels.
    //
    // Example usage in application code:
    //   $metrics->counter('orders_created_total', ['channel' => 'web'])->increment();
    //   $metrics->histogram('checkout_duration_ms', ['variant' => 'one_page'])->observe($durationMs);
    //
    // Keep labels low-cardinality. Use values like route, status, command, tenant tier,
    // or feature variant. Never use user IDs, emails, request IDs, raw URLs, or order IDs.
    //
    // $config
    //     ->counter(
    //         'orders_created_total',
    //         'Total orders created by sales channel.',
    //         ['channel'],
    //     )
    //     ->histogram(
    //         'checkout_duration_ms',
    //         'Checkout duration in milliseconds by checkout variant.',
    //         ['variant'],
    //         [10, 25, 50, 100, 250, 500, 1000, 2500],
    //     )
    // ;

    // Operational messaging gauges are built in when the Messaging module and
    // Doctrine DBAL are installed:
    //
    //   outbox_backlog_size{transport,status}
    //   outbox_oldest_pending_age_seconds{transport}
    //   dlq_backlog_size{transport,event}
    //   dlq_oldest_failed_age_seconds{transport}
    //
    // Prometheus refreshes these during /metrics scrapes. Push backends such as
    // StatsD should run this from cron or a worker:
    //
    //   php bin/console vortos:metrics:collect

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

    // OpenTelemetry OTLP push — only relevant when adapter = OpenTelemetry.
    //
    // Vortos uses the official OpenTelemetry PHP SDK/exporter. Flush is lifecycle-driven:
    // forceFlush() runs on request/command terminate, and shutdown() is reserved for
    // process exit so FrankenPHP workers keep recording after the first request.
    //
    // Keep timeoutMs low. Monitoring must never hold a PHP worker hostage.
    //
    // $config
    //     ->adapter(MetricsAdapter::OpenTelemetry)
    //     ->service(
    //         $_ENV['OTEL_SERVICE_NAME'] ?? 'app',
    //         version: $_ENV['APP_VERSION'] ?? '',
    //         environment: $_ENV['APP_ENV'] ?? 'prod',
    //     )
    //     ->otlp(
    //         $_ENV['OTEL_EXPORTER_OTLP_METRICS_ENDPOINT'] ?? 'http://otel-collector:4318/v1/metrics',
    //         headers: [],
    //         timeoutMs: 200,
    //     )
    // ;
    //
    // Vendor helpers are endpoint/header shortcuts only:
    //   $config->newRelicOtlp($_ENV['NEW_RELIC_LICENSE_KEY'] ?? '');
    //   $config->datadogOtlp($_ENV['DD_API_KEY'] ?? '', site: 'datadoghq.com');
};
