<?php

declare(strict_types=1);

namespace Vortos\Tracing\OpenTelemetry;

use Vortos\Tracing\Contract\TracingInterface;

final class OpenTelemetryTracerFactory
{
    /**
     * @param array{service_name: string, service_version: string, deployment_environment: string, endpoint: string, protocol: string, headers: array<string, string>, timeout_ms: int} $config
     */
    public static function create(array $config): TracingInterface
    {
        foreach ([
            'OpenTelemetry\API\Trace\TracerProviderInterface',
            'OpenTelemetry\API\Globals',
            'OpenTelemetry\API\Trace\Propagation\TraceContextPropagator',
            'OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory',
            'OpenTelemetry\SDK\Trace\TracerProvider',
            'OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessorBuilder',
            'OpenTelemetry\Contrib\Otlp\SpanExporter',
            'OpenTelemetry\SDK\Common\Attribute\Attributes',
            'OpenTelemetry\SDK\Resource\ResourceInfo',
            'OpenTelemetry\SDK\Resource\ResourceInfoFactory',
        ] as $class) {
            if (!class_exists($class) && !interface_exists($class)) {
                throw new \RuntimeException(
                    'vortos-tracing: OpenTelemetry adapter requires open-telemetry/api, open-telemetry/sdk, and open-telemetry/exporter-otlp. '
                    . 'Install them or use TracingAdapter::NoOp.',
                );
            }
        }

        $transportFactoryClass = 'OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory';
        $tracerProviderClass = 'OpenTelemetry\SDK\Trace\TracerProvider';
        $spanProcessorBuilderClass = 'OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessorBuilder';
        $spanExporterClass = 'OpenTelemetry\Contrib\Otlp\SpanExporter';
        $attributesClass = 'OpenTelemetry\SDK\Common\Attribute\Attributes';
        $resourceInfoClass = 'OpenTelemetry\SDK\Resource\ResourceInfo';
        $resourceInfoFactoryClass = 'OpenTelemetry\SDK\Resource\ResourceInfoFactory';

        $transport = (new $transportFactoryClass())->create(
            $config['endpoint'],
            'application/x-protobuf',
            $config['headers'],
            null,
            $config['timeout_ms'] / 1000,
        );
        $exporter = new $spanExporterClass(
            $transport,
        );
        $processor = (new $spanProcessorBuilderClass($exporter))->build();
        $resource = $resourceInfoFactoryClass::defaultResource()->merge($resourceInfoClass::create($attributesClass::create([
            'service.name' => $config['service_name'],
            'service.version' => $config['service_version'],
            'deployment.environment.name' => $config['deployment_environment'],
        ])));
        $provider = new $tracerProviderClass($processor, null, $resource);
        $tracer = $provider->getTracer($config['service_name'], $config['service_version']);

        return new OpenTelemetryTracer(
            $tracer,
            \OpenTelemetry\API\Trace\Propagation\TraceContextPropagator::getInstance(),
            static function () use ($provider): void {
                if (method_exists($provider, 'shutdown')) {
                    $provider->shutdown();
                }
            },
        );
    }
}
