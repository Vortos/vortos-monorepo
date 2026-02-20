<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Contract\Tracing;

use Throwable;

/**
 * Represents a single unit of work in a distributed trace.
 *
 * Always call end() after the work is complete, in a finally block.
 * Status values: 'ok' for success, 'error' for failures.
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