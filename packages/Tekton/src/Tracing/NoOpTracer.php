<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Tracing;

use Fortizan\Tekton\Tracing\Contract\SpanInterface;
use Fortizan\Tekton\Tracing\Contract\TracingInterface;

/**
 * No-operation tracer. Default TracingInterface implementation.
 * 
 * Used when no tracing backend is configured. All methods are no-ops.
 * startSpan() returns a NoOpSpan — safe to call end(), setStatus() etc. on.
 * currentCorrelationId() returns null — EventBus falls back to generating a fresh ID.
 * 
 * Replace with OpenTelemetryTracer in the DI container to enable distributed tracing.
 */
final class NoOpTracer implements TracingInterface
{
    public function startSpan(string $name, array $attributes = []): SpanInterface 
    {
        return new NoOpSpan();
    }

    public function injectHeaders(array &$headers): void {}

    public function extractContext(array $headers): void {}

    public function currentCorrelationId(): ?string 
    {
        return null;
    }
}