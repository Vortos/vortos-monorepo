<?php

declare(strict_types=1);

namespace Vortos\Tracing\OpenTelemetry;

use Vortos\Tracing\Contract\SpanInterface;
use OpenTelemetry\API\Trace\SpanInterface as OTelSpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
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
    private bool $ended = false;

    public function __construct(
        private OTelSpanInterface $span,
        private ?ScopeInterface $scope = null,
        private ?\Closure $onEnd = null,
    ){
    }

    public function end(): void
    {
        if ($this->ended) {
            return;
        }

        $this->ended = true;

        if ($this->scope !== null) {
            $this->scope->detach();
            $this->scope = null;
        }

        $this->span->end();

        if ($this->onEnd !== null) {
            ($this->onEnd)();
            $this->onEnd = null;
        }
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
