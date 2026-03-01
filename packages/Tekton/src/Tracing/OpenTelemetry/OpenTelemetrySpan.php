<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Tracing\OpenTelemetry;

use Fortizan\Tekton\Tracing\Contract\SpanInterface;
use OpenTelemetry\API\Trace\SpanInterface as OTelSpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use Throwable;

/**
 * OpenTelemetry span adapter.
 * 
 * Wraps the OTel SDK SpanInterface and delegates all operations to it.
 * Translates the framework's string status ('ok', 'error') to OTel StatusCode constants.
 * 
 * Requires: open-telemetry/api
 */
final class OpenTelemetrySpan implements SpanInterface
{
    public function __construct(
        private OTelSpanInterface $span
    ){
    }

    public function end(): void
    {
        $this->span->end();
    }

    public function addAttribute(string $key, mixed $value): void
    {
        $this->span->setAttribute($key, $value);
    }

    public function recordException(Throwable $e): void
    {
        $this->span->recordException($e);
    }

    public function setStatus(string $status): void
    {
        $statusCode = $status === 'error' ? StatusCode::STATUS_ERROR : StatusCode::STATUS_OK;
        $this->span->setStatus($statusCode);
    }
}