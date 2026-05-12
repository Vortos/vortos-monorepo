<?php

declare(strict_types=1);

use Vortos\Tracing\Config\TracingAdapter;
use Vortos\Tracing\Config\TracingSampler;
use Vortos\Tracing\DependencyInjection\VortosTracingConfig;
use Vortos\Observability\Config\ObservabilityModule;

// Vortos ships a NoOp tracer by default. Use it when a service is not connected
// to a collector. Switch to OpenTelemetry only after OTLP is available.
//
// Environment defaults (when this file is absent):
//   dev  → AlwaysOn sampler (trace every request)
//   prod → Ratio sampler at 10 % (sample 1 in 10 requests)
//
// For per-environment overrides create config/{env}/tracing.php.

return static function (VortosTracingConfig $config): void {
    $config
        // NoOp has no exporter work. OpenTelemetry requires:
        // composer require open-telemetry/api open-telemetry/sdk open-telemetry/exporter-otlp
        ->adapter(TracingAdapter::NoOp)

        // Service metadata is sent to the collector when OpenTelemetry is enabled.
        ->service(
            name: $_ENV['OTEL_SERVICE_NAME'] ?? $_ENV['APP_NAME'] ?? 'vortos-app',
            version: $_ENV['APP_VERSION'] ?? '',
            environment: $_ENV['APP_ENV'] ?? 'prod',
        )

        // OTLP HTTP/protobuf exporter settings. The exporter uses a batch span
        // processor so request paths do not synchronously export every span.
        ->otlp(
            endpoint: $_ENV['OTEL_EXPORTER_OTLP_TRACES_ENDPOINT'] ?? 'http://otel-collector:4318/v1/traces',
            headers: [],
            timeoutMs: 2000,
        )

        // Sampling strategy — controls what fraction of requests are traced.
        // Sampling is parent-based and trace-level, so child spans follow the
        // parent decision instead of creating partial broken traces.
        //
        // TracingSampler::AlwaysOn  — trace every request (dev / low-traffic)
        // TracingSampler::AlwaysOff — disable tracing entirely
        // TracingSampler::Ratio     — probabilistic; set $rate between 0.0 and 1.0
        //                             0.1 = 10 % of requests (prod default)
        ->sampler(TracingSampler::Ratio, rate: 0.1)

        // Trust incoming W3C traceparent headers to set the parent span.
        //
        // Only enable when ALL traffic comes from internal services you control.
        // An external caller with a crafted traceparent can inject trace IDs
        // into your backend. Leave false for internet-facing services.
        ->trustRemoteContext(false)
    ;

    // Disable auto-instrumentation for specific modules.
    // Useful to reduce span noise for high-frequency modules.
    //
    // $config->disable(
    //     ObservabilityModule::Cache,        // suppress cache get/set spans
    //     ObservabilityModule::Persistence,  // suppress DB query spans
    //     ObservabilityModule::Messaging,    // suppress event/consumer spans
    // );

    // Controller attributes:
    //
    // #[TraceWith(spanName: 'checkout.place_order', sampleRate: 1.0)]
    // #[DisableTracing]
    //
    // Baggage is propagated across HTTP/Kafka only for small, non-sensitive,
    // low-cardinality values such as tenant id. Never put PII or secrets in it.
};
