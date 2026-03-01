<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Middleware\Core;

use Fortizan\Tekton\Messaging\Bus\Stamp\CorrelationIdStamp;
use Fortizan\Tekton\Messaging\Middleware\MiddlewareInterface;
use Fortizan\Tekton\Tracing\Contract\TracingInterface;
use Symfony\Component\Messenger\Envelope;
use Throwable;

/**
 * Creates a tracing span for each event dispatched through the bus.
 * Span name is the event class name. Correlation ID is added as a span attribute.
 * Span status is set to 'ok' on success and 'error' on exception.
 * The span is always ended in a finally block — never leaked.
 */
final class TracingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private TracingInterface $tracer
    ){
    }

    public function handle(Envelope $envelope, callable $next):Envelope
    {
        $eventClass = get_class($envelope->getMessage());
        $correlationStamp = $envelope->last(CorrelationIdStamp::class);

        $span = $this->tracer->startSpan(
            $eventClass,
            [
                'correlation_id' => $correlationStamp?->correlationId ?? 'none'
            ]
        );

        try {
            $result = $next($envelope);
            $span->setStatus('ok');
            return $result;
        } catch (Throwable $e) {
            $span->recordException($e);
            $span->setStatus('error');
            throw $e;
        } finally {
            $span->end();
        }

    }
}