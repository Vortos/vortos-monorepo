<?php

declare(strict_types=1);

namespace Vortos\Metrics\OpenTelemetry;

use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
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
     *     namespace: string
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
        $exporter = new MetricExporter($transport);
        $reader = new ExportingReader($exporter);
        $resource = ResourceInfoFactory::defaultResource()->merge(ResourceInfo::create(Attributes::create([
            'service.name' => $config['service_name'],
            'service.version' => $config['service_version'],
            'deployment.environment.name' => $config['deployment_environment'],
        ])));
        $provider = MeterProvider::builder()
            ->setResource($resource)
            ->addReader($reader)
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
