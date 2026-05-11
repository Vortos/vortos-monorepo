# Vortos Tracing

The tracing module provides a small framework abstraction with NoOp defaults and OpenTelemetry support for production deployments.

## Defaults

- `TracingAdapter::NoOp` is the default and performs no export work.
- Development uses `AlwaysOn` sampling.
- Production uses ratio sampling at `0.1`.
- Sampling is parent-based and trace-level, so child spans follow the root or remote parent decision.
- Incoming `traceparent` headers are not trusted by default.
- HTTP instrumentation avoids query strings.
- Messaging propagation uses W3C trace context headers.
- Baggage is supported for small, non-sensitive, low-cardinality values.

## Enable OpenTelemetry

Install the required packages:

```bash
composer require open-telemetry/api open-telemetry/sdk open-telemetry/exporter-otlp
```

Configure OTLP:

```php
use Vortos\Tracing\Config\TracingAdapter;
use Vortos\Tracing\Config\TracingSampler;
use Vortos\Tracing\DependencyInjection\VortosTracingConfig;

return static function (VortosTracingConfig $config): void {
    $config
        ->adapter(TracingAdapter::OpenTelemetry)
        ->service(
            name: $_ENV['OTEL_SERVICE_NAME'] ?? $_ENV['APP_NAME'] ?? 'checkout-api',
            version: $_ENV['APP_VERSION'] ?? '',
            environment: $_ENV['APP_ENV'] ?? 'prod',
        )
        ->otlp(
            endpoint: $_ENV['OTEL_EXPORTER_OTLP_TRACES_ENDPOINT'] ?? 'http://otel-collector:4318/v1/traces',
            headers: [],
            timeoutMs: 2000,
        )
        ->sampler(TracingSampler::Ratio, rate: 0.1)
        ->trustRemoteContext(false);
};
```

The OpenTelemetry adapter requires the SDK and OTLP exporter. If they are missing, configuration fails instead of silently running without export.

## Controller Attributes

```php
use Vortos\Tracing\Attribute\DisableTracing;
use Vortos\Tracing\Attribute\TraceWith;

#[TraceWith(spanName: 'checkout.place_order', sampleRate: 1.0)]
public function checkout(): Response
{
    // ...
}

#[DisableTracing]
public function health(): Response
{
    // ...
}
```

`TraceWith` customizes the HTTP span name and can override the sample rate for that endpoint. `DisableTracing` prevents controller-level HTTP span creation.

## Baggage

Use baggage only for safe routing context, for example tenant id:

```php
$tracer->setBaggageItem('tenant.id', $tenantId);
```

Never put PII, secrets, session ids, JWTs, emails, phone numbers, payment data, or high-cardinality values in baggage. Baggage is propagated to downstream services.

