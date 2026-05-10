<?php

declare(strict_types=1);

use Vortos\Tracing\Config\TracingModule;
use Vortos\Tracing\Config\TracingSampler;
use Vortos\Tracing\DependencyInjection\VortosTracingConfig;

// Vortos ships a NoOp tracer — no overhead until you wire an OpenTelemetry
// exporter. Sampling and module toggles below still apply once you do.
//
// Environment defaults (when this file is absent):
//   dev  → AlwaysOn sampler (trace every request)
//   prod → Ratio sampler at 10 % (sample 1 in 10 requests)
//
// For per-environment overrides create config/{env}/tracing.php.

return static function (VortosTracingConfig $config): void {
    $config
        // Sampling strategy — controls what fraction of requests are traced.
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
    //     TracingModule::Cache,        // suppress cache get/set spans
    //     TracingModule::Persistence,  // suppress DB query spans
    // );
};
