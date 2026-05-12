<?php

declare(strict_types=1);

namespace Vortos\Metrics\Adapter;

use OpenTelemetry\API\Metrics\CounterInterface as OTelCounterInterface;
use OpenTelemetry\API\Metrics\GaugeInterface as OTelGaugeInterface;
use OpenTelemetry\API\Metrics\HistogramInterface as OTelHistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use Psr\Log\LoggerInterface;
use Vortos\Metrics\Contract\CounterInterface;
use Vortos\Metrics\Contract\FlushableMetricsInterface;
use Vortos\Metrics\Contract\GaugeInterface;
use Vortos\Metrics\Contract\HistogramInterface;
use Vortos\Metrics\Contract\MetricsInterface;
use Vortos\Metrics\Contract\ShutdownMetricsInterface;
use Vortos\Metrics\Definition\MetricDefinitionRegistry;
use Vortos\Metrics\Definition\MetricType;
use Vortos\Metrics\Instrument\OpenTelemetryCounter;
use Vortos\Metrics\Instrument\OpenTelemetryGauge;
use Vortos\Metrics\Instrument\OpenTelemetryHistogram;

final class OpenTelemetryMetrics implements MetricsInterface, FlushableMetricsInterface, ShutdownMetricsInterface
{
    /** @var array<string, OTelCounterInterface> */
    private array $counters = [];
    /** @var array<string, OTelGaugeInterface> */
    private array $gauges = [];
    /** @var array<string, OTelHistogramInterface> */
    private array $histograms = [];
    /** @var array<string, float> */
    private array $gaugeValues = [];

    public function __construct(
        private readonly MeterProviderInterface $meterProvider,
        private readonly MeterInterface $meter,
        private readonly MetricDefinitionRegistry $definitions,
        private readonly string $namespace = 'vortos',
        private readonly ?LoggerInterface $logger = null,
    ) {
        register_shutdown_function($this->shutdown(...));
    }

    public function counter(string $name, array $labels = []): CounterInterface
    {
        $definition = $this->definitions->requireType($name, MetricType::Counter);
        $orderedLabels = $this->definitions->validateLabels($definition, $labels);
        $metricName = $this->metricName($name);

        return new OpenTelemetryCounter(
            $this->counters[$name] ??= $this->meter->createCounter($metricName, null, $definition->help),
            $orderedLabels,
        );
    }

    public function gauge(string $name, array $labels = []): GaugeInterface
    {
        $definition = $this->definitions->requireType($name, MetricType::Gauge);
        $orderedLabels = $this->definitions->validateLabels($definition, $labels);
        $metricName = $this->metricName($name);

        $labelKey = $this->labelKey($name, $orderedLabels);

        return new OpenTelemetryGauge(
            $this->gauges[$name] ??= $this->meter->createGauge($metricName, null, $definition->help),
            $orderedLabels,
            function (float $value) use ($labelKey): void {
                $this->gaugeValues[$labelKey] = $value;
            },
            function (float $delta) use ($labelKey): float {
                return ($this->gaugeValues[$labelKey] ?? 0.0) + $delta;
            },
        );
    }

    public function histogram(string $name, array $labels = []): HistogramInterface
    {
        $definition = $this->definitions->requireType($name, MetricType::Histogram);
        $orderedLabels = $this->definitions->validateLabels($definition, $labels);
        $metricName = $this->metricName($name);

        return new OpenTelemetryHistogram(
            $this->histograms[$name] ??= $this->meter->createHistogram($metricName, null, $definition->help, [
                'explicit_bucket_boundaries' => $definition->buckets,
            ]),
            $orderedLabels,
        );
    }

    public function flush(): void
    {
        try {
            $this->meterProvider->forceFlush();
        } catch (\Throwable $e) {
            $this->logger?->warning('metrics.otlp.flush_failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function shutdown(): void
    {
        try {
            $this->meterProvider->shutdown();
        } catch (\Throwable) {
        }
    }

    /** @return array{counters: int, gauges: int, histograms: int} */
    public function cacheStats(): array
    {
        return [
            'counters' => count($this->counters),
            'gauges' => count($this->gauges),
            'histograms' => count($this->histograms),
        ];
    }

    /** @param array<string, string> $labels */
    private function labelKey(string $name, array $labels): string
    {
        ksort($labels);

        return $name . ':' . hash('xxh128', json_encode($labels, JSON_THROW_ON_ERROR));
    }

    private function metricName(string $name): string
    {
        return $this->namespace . '_' . $name;
    }
}
