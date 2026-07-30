<?php

declare(strict_types=1);

namespace Vortos\Metrics\OpenTelemetry;

use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\Exemplar\ExemplarFilter\WithSampledTraceExemplarFilter;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use Psr\Log\LoggerInterface;
use Vortos\Metrics\Adapter\OpenTelemetryMetrics;
use Vortos\Metrics\Definition\MetricDefinitionRegistry;

final class OpenTelemetryMetricsFactory
{
    /**
     * @param array{
     *     service_name: string,
     *     service_version: string,
     *     deployment_environment: string,
     *     endpoint: string,
     *     headers: array<string, string>,
     *     timeout_ms: int,
     *     namespace: string,
     *     temporality: string,
     *     service_instance_id?: string
     * } $config
     */
    public static function create(
        array $config,
        MetricDefinitionRegistry $definitions,
        ?LoggerInterface $logger = null,
    ): OpenTelemetryMetrics {
        foreach ([
            MeterProvider::class,
            ExportingReader::class,
            MetricExporter::class,
            OtlpHttpTransportFactory::class,
            Attributes::class,
            ResourceInfo::class,
            ResourceInfoFactory::class,
        ] as $class) {
            if (!class_exists($class)) {
                throw new \RuntimeException(
                    'vortos-metrics: OpenTelemetry adapter requires open-telemetry/sdk, open-telemetry/exporter-otlp, and a PSR-18 HTTP client such as guzzlehttp/guzzle.',
                );
            }
        }

        $transport = (new OtlpHttpTransportFactory())->create(
            $config['endpoint'],
            'application/x-protobuf',
            self::headers($config['headers']),
            null,
            $config['timeout_ms'] / 1000,
            retryDelay: 0,
            maxRetries: 0,
        );
        // Pin the aggregation temporality explicitly. Without this the exporter falls back to
        // per-instrument temporality (delta for counters/histograms), which Prometheus-compatible
        // OTLP backends — Grafana Cloud / Mimir, and the Prometheus OTLP receiver — reject with
        // HTTP 400 "invalid temporality and type combination". Cumulative is the correct, portable
        // default (also accepted by New Relic and Datadog) and is self-healing under a per-request
        // flush worker model; delta is an opt-in for delta-native backends. Configurable via
        // VortosMetricsConfig::metricsTemporality().
        $exporter = new MetricExporter($transport, self::temporalityToken($config['temporality']));
        $reader = new ExportingReader($exporter);
        $resource = self::resource($config);
        $provider = MeterProvider::builder()
            ->setResource($resource)
            ->addReader($reader)
            // Exemplars attach the trace id of a sampled request to the metric point it produced,
            // which is what turns "p99 latency spiked" into one click through to the exact trace
            // that was slow. Without them the two signals are already being collected but cannot be
            // joined, and every latency investigation restarts from scratch in the trace explorer.
            //
            // WithSampledTrace, not All: an exemplar is only useful if the trace it points at was
            // actually kept. Recording exemplars for unsampled spans produces links that dead-end,
            // and costs cardinality for nothing.
            ->setExemplarFilter(new WithSampledTraceExemplarFilter())
            ->build();

        return new OpenTelemetryMetrics(
            $provider,
            $provider->getMeter($config['service_name'], $config['service_version']),
            $definitions,
            $config['namespace'],
            $logger,
        );
    }

    /**
     * The OTLP resource every exported data point is stamped with.
     *
     * `service.instance.id` is the load-bearing one, and it is not decoration. Without it every
     * FrankenPHP worker thread exports its own independent cumulative counter under one identical
     * resource identity. A Prometheus-compatible backend then sees a single series whose value keeps
     * jumping backwards as different threads report, treats each decrease as a counter reset, and
     * re-adds the whole total — inflating every rate() and increase() by roughly an order of magnitude
     * while understating the absolute total. {@see ServiceInstanceId} carries the measured numbers and
     * why delta temporality is not an available fix.
     *
     * Separated from {@see create()} purely so it can be asserted on without standing up a real OTLP
     * transport: create() builds its own transport from the endpoint, so the resource would otherwise
     * only be observable by making a network call.
     *
     * @param array{
     *     service_name: string,
     *     service_version: string,
     *     deployment_environment: string,
     *     service_instance_id?: string
     * } $config
     *
     * @internal
     */
    public static function resource(array $config): ResourceInfo
    {
        return ResourceInfoFactory::defaultResource()->merge(ResourceInfo::create(Attributes::create([
            'service.name' => $config['service_name'],
            'service.version' => $config['service_version'],
            'deployment.environment.name' => $config['deployment_environment'],
            'service.instance.id' => ServiceInstanceId::resolve($config['service_instance_id'] ?? ''),
        ])));
    }

    /**
     * Maps a {@see \Vortos\Metrics\Config\MetricTemporality} value to the SDK temporality token
     * consumed by {@see MetricExporter}. Unknown values fall back to the safe, portable
     * cumulative default rather than throwing — a bad config value must never disable delivery.
     *
     * @internal
     * @return Temporality::DELTA|Temporality::CUMULATIVE
     */
    public static function temporalityToken(string $temporality): string
    {
        return match ($temporality) {
            'delta' => Temporality::DELTA,
            default => Temporality::CUMULATIVE,
        };
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private static function headers(array $headers): array
    {
        $filtered = [];
        foreach ($headers as $name => $value) {
            if ($name === '' || $value === '') {
                continue;
            }

            if (preg_match('/[\r\n]/', $name . $value) === 1) {
                throw new \InvalidArgumentException('OTLP metric headers must not contain CRLF characters.');
            }

            $filtered[$name] = $value;
        }

        return $filtered;
    }
}
