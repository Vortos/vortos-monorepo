<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Middleware\Core;

use Fortizan\Tekton\Messaging\Bus\Stamp\CorrelationIdStamp;
use Fortizan\Tekton\Messaging\Bus\Stamp\HandlerStamp;
use Fortizan\Tekton\Messaging\Middleware\MiddlewareInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Throwable;

/**
 * Logs every event dispatch with timing, handler ID, and correlation ID.
 * Uses 'debug' level on success and 'error' level on failure.
 * Duration is measured in milliseconds and rounded to 2 decimal places.
 */
final class LoggingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private LoggerInterface $logger
    ){
    }

    public function handle(Envelope $envelope, callable $next): Envelope
    {
        $startTime = microtime(true);
        $eventClass = get_class($envelope->getMessage());

        $correlationStamp = $envelope->last(CorrelationIdStamp::class);
        $handlerStamp = $envelope->last(HandlerStamp::class);

        try {
            $result = $next($envelope);

            $durationMs = (microtime(true) - $startTime)* 1000;
            $this->logger->debug(
                'Event dispatched',
                [
                    'event' => $eventClass,
                    'handler' => $handlerStamp?->handlerId ?? 'unknown',
                    'correlation_id' => $correlationStamp?->correlationId ?? 'none',
                    'duration_ms' => round($durationMs, 2)
                ]
            );

            return $result;
        } catch (Throwable $e) {
            $durationMs = (microtime(true) - $startTime) * 1000;

            $this->logger->error(
                'Event dispatch failed',
                [
                    'event' => $eventClass,
                    'handler' => $handlerStamp?->handlerId ?? 'unknown',
                    'correlation_id' => $correlationStamp?->correlationId ?? 'none',
                    'duration_ms' => round($durationMs, 2),
                    'exception' => $e->getMessage()
                ]
            );

            throw $e;
        }
    }
}