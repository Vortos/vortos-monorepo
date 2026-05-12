<?php

declare(strict_types=1);

namespace Vortos\Tests\Metrics;

use OpenTelemetry\SDK\Metrics\MetricExporter\NoopMetricExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use PHPUnit\Framework\TestCase;
use Vortos\Metrics\Adapter\OpenTelemetryMetrics;
use Vortos\Metrics\Definition\MetricDefinition;
use Vortos\Metrics\Definition\MetricDefinitionRegistry;

final class OpenTelemetryMetricsTest extends TestCase
{
    public function test_instrument_cache_is_bounded_by_metric_name_across_flushes(): void
    {
        $provider = MeterProvider::builder()
            ->addReader(new ExportingReader(new NoopMetricExporter()))
            ->build();
        $registry = new MetricDefinitionRegistry([
            MetricDefinition::counter('orders_total', 'Orders.', ['route']),
            MetricDefinition::gauge('queue_depth', 'Queue depth.', ['queue']),
            MetricDefinition::histogram('checkout_ms', 'Checkout duration.', ['route'], [10, 100]),
        ]);
        $metrics = new OpenTelemetryMetrics($provider, $provider->getMeter('test'), $registry);

        for ($i = 0; $i < 50; $i++) {
            $metrics->counter('orders_total', ['route' => 'checkout'])->increment();
            $metrics->gauge('queue_depth', ['queue' => 'default'])->set((float) $i);
            $metrics->histogram('checkout_ms', ['route' => 'checkout'])->observe(12.0);
            $metrics->flush();
        }

        $this->assertSame(['counters' => 1, 'gauges' => 1, 'histograms' => 1], $metrics->cacheStats());
    }
}
