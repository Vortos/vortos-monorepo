<?php

declare(strict_types=1);

namespace Vortos\Tracing\OpenTelemetry;

use Vortos\Tracing\Contract\TracingInterface;

final class OpenTelemetryTracerFactory
{
    /**
     * @param array{service_name: string, service_version: string, deployment_environment: string, endpoint: string, protocol: string, headers: array<string, string>, timeout_ms: int, span_attribute_count_limit?: int, span_event_count_limit?: int, span_link_count_limit?: int} $config
     */
    public static function create(array $config): TracingInterface
    {
        foreach ([
            'OpenTelemetry\API\Trace\TracerProviderInterface',
            'OpenTelemetry\API\Globals',
            'OpenTelemetry\API\Trace\Propagation\TraceContextPropagator',
            'OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory',
            'OpenTelemetry\SDK\Trace\TracerProvider',
            'OpenTelemetry\SDK\Trace\SpanLimitsBuilder',
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
        $spanLimitsBuilderClass = 'OpenTelemetry\SDK\Trace\SpanLimitsBuilder';
        $spanProcessorBuilderClass = 'OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessorBuilder';
        $spanExporterClass = 'OpenTelemetry\Contrib\Otlp\SpanExporter';
        $attributesClass = 'OpenTelemetry\SDK\Common\Attribute\Attributes';
        $resourceInfoClass = 'OpenTelemetry\SDK\Resource\ResourceInfo';
        $resourceInfoFactoryClass = 'OpenTelemetry\SDK\Resource\ResourceInfoFactory';

        // Fire-and-forget: NO in-process retries (retryDelay/maxRetries = 0). Telemetry
        // export must never block or sleep-retry in the request/worker path — a slow or
        // unreachable collector would otherwise stall the served application (the OTLP
        // default is 3 retries with backoff, which can add many seconds per request).
        // Durability/retry is the collector agent's job, not the app's. Matches the
        // metrics exporter. Pair with a LOCAL collector endpoint so connect is instant.
        $transport = (new $transportFactoryClass())->create(
            $config['endpoint'],
            'application/x-protobuf',
            $config['headers'],
            null,
            $config['timeout_ms'] / 1000,
            retryDelay: 0,
            maxRetries: 0,
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

        // Explicit per-span limits. Without these the SDK caps every span at
        // 128 attributes/events/links and silently drops the overflow while
        // logging "Dropped span attributes, links or events" — noisy under an
        // AlwaysOn sampler. Raising the caps stops the drops and the spam.
        $spanLimits = (new $spanLimitsBuilderClass())
            ->setAttributeCountLimit($config['span_attribute_count_limit'] ?? 256)
            ->setEventCountLimit($config['span_event_count_limit'] ?? 256)
            ->setLinkCountLimit($config['span_link_count_limit'] ?? 256)
            ->build();

        $provider = new $tracerProviderClass($processor, null, $resource, $spanLimits);
        $tracer = $provider->getTracer($config['service_name'], $config['service_version']);

        return new OpenTelemetryTracer(
            $tracer,
            \OpenTelemetry\API\Trace\Propagation\TraceContextPropagator::getInstance(),
            static function () use ($provider): void {
                // Fail-open: a failing/slow export on shutdown must never surface as an
                // application error. Swallow anything the flush throws.
                try {
                    if (method_exists($provider, 'shutdown')) {
                        $provider->shutdown();
                    }
                } catch (\Throwable) {
                    // telemetry is best-effort — never let it break the request/worker
                }
            },
        );
    }
}
