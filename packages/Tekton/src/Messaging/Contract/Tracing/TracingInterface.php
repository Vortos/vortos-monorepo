<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Contract\Tracing;

/**
 * Distributed tracing abstraction.
 *
 * Default implementation is NoOpTracer (zero overhead).
 * OpenTelemetryTracer is the production implementation.
 * Tracing context is propagated via W3C traceparent/tracestate headers
 * injected into message headers on produce and extracted on consume.
 */
interface TracingInterface
{
    /**
     * Start a new tracing span with the given name and optional attributes.
     * Always call end() on the returned span, preferably in a finally block.
     */
    public function startSpan(string $name, array $attributes = []): SpanInterface;

    /**
     * Inject the current trace context into an outgoing message's headers array.
     * Modifies $headers in place. Call this before producing a message.
     */
    public function injectHeaders(array &$headers): void;

    /**
     * Restore trace context from an incoming message's headers.
     * Call this at the start of message processing before startSpan().
     */
    public function extractContext(array $headers): void;
}