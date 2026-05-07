<?php

declare(strict_types=1);

use Vortos\Metrics\Config\MetricsAdapter;
use Vortos\Metrics\Config\MetricsModule;
use Vortos\Metrics\DependencyInjection\VortosMetricsConfig;

/**
 * Vortos Metrics configuration.
 *
 * Default adapter is NoOp — zero overhead, no dependencies.
 * Enable Prometheus or StatsD when you need real metrics.
 *
 * ## Quick start: Prometheus
 *
 *   $config
 *       ->adapter(MetricsAdapter::Prometheus)
 *       ->prometheusStorageRedis(prefix: 'metrics:')   // multi-process safe (FrankenPHP)
 *       ->prometheusEndpointToken($_ENV['METRICS_TOKEN'] ?? '');
 *
 * ## Quick start: StatsD / Datadog
 *
 *   $config
 *       ->adapter(MetricsAdapter::StatsD)
 *       ->statsDHost($_ENV['STATSD_HOST'] ?? '127.0.0.1')
 *       ->statsDPort((int) ($_ENV['STATSD_PORT'] ?? 8125));
 *
 * ## Disabling noisy modules
 *
 *   $config->disableModule(MetricsModule::Cache);       // too many cache ops
 *   $config->disableModule(MetricsModule::Persistence); // too many DB queries
 */
return static function (VortosMetricsConfig $config): void {
    // $config->adapter(MetricsAdapter::Prometheus);
    // $config->namespace('myapp');
    // $config->prometheusStorageRedis(prefix: 'metrics:');
    // $config->prometheusEndpoint('/metrics');
    // $config->prometheusEndpointToken($_ENV['METRICS_TOKEN'] ?? '');

    // $config->adapter(MetricsAdapter::StatsD);
    // $config->statsDHost($_ENV['STATSD_HOST'] ?? '127.0.0.1');
    // $config->statsDPort((int) ($_ENV['STATSD_PORT'] ?? 8125));
    // $config->statsDSampleRate(1.0);

    // $config->disableModule(MetricsModule::Cache, MetricsModule::Persistence);
};
