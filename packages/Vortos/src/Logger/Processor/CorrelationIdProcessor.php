<?php

declare(strict_types=1);

namespace Vortos\Logger\Processor;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Vortos\Tracing\Contract\TracingInterface;

/**
 * Injects the active trace ID into every log record as the 'trace_id' extra field.
 *
 * Bridges logs and traces: every log line carries the trace ID of the request
 * that produced it, making log/trace correlation trivial in any aggregator.
 *
 * When TracingInterface is NoOpTracer (default), currentCorrelationId() returns
 * null and this processor is a zero-overhead no-op.
 */
final class CorrelationIdProcessor implements ProcessorInterface
{
    public function __construct(private readonly TracingInterface $tracer) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        $traceId = $this->tracer->currentCorrelationId();

        if ($traceId === null) {
            return $record;
        }

        return $record->with(extra: [
            ...$record->extra,
            'trace_id' => $traceId,
        ]);
    }
}
