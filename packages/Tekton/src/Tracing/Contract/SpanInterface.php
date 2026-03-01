<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Tracing\Contract;

use Throwable;

/**
 * Represents an active tracing span.
 * 
 * A span tracks a single unit of work — a handler execution, a broker produce call,
 * a database query. Created by TracingInterface::startSpan() and must always be
 * ended via end(), typically in a finally block to prevent span leaks.
 * 
 * Implementations: NoOpSpan (default, zero overhead), OpenTelemetrySpan (OTel SDK).
 */
interface SpanInterface
{
    /**
     * Mark the span as finished. Must be called exactly once.
     */
    public function end():void;

    /**
     * Add a key-value attribute to the span for additional context.
     * Examples: event class name, consumer name, transport name.
     */
    public function addAttribute(string $key, mixed $value): void;

    /**
     * Record an exception on the span without ending it.
     * Use this inside catch blocks before rethrowing.
     */
    public function recordException(Throwable $e): void;

    /**
     * Set the span status. Valid values: 'ok', 'error'.
     */
    public function setStatus(string $status):void;
}