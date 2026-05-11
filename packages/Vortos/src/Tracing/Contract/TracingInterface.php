<?php

declare(strict_types=1);

namespace Vortos\Tracing\Contract;

/**
 * Framework tracing abstraction.
 * 
 * Decouples all framework components from any specific tracing SDK.
 * Default implementation is NoOpTracer — zero overhead, no dependencies.
 * Replace with OpenTelemetryTracer when distributed tracing is required.
 * 
 * Used by:
 * - TracingMiddleware: wraps each handler execution in a span
 * - EventBus: propagates correlation ID from HTTP context into dispatched events
 * - KafkaProducer: injects W3C trace headers into outgoing messages
 * - KafkaConsumer: restores trace context from incoming message headers
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

    /**
     * Store low-cardinality cross-service baggage for the current trace.
     *
     * Never put secrets, tokens, email addresses, or free-form user input in baggage.
     */
    public function setBaggageItem(string $key, string $value): void;

    public function baggageItem(string $key): ?string;

    /**
     * @return array<string, string>
     */
    public function baggage(): array;

    /**
     * Returns the current trace/correlation ID from the active span context.
     * Returns null when no active span exists — CLI, tests, or before first request.
     * EventBus uses this to carry the HTTP request trace ID into dispatched events
     * so the full chain (request → domain event → consumer → side effects) appears
     * as one trace in the backend.
     */
    public function currentCorrelationId(): ?string;
}
