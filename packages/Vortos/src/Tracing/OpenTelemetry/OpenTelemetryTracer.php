<?php

declare(strict_types=1);

namespace Vortos\Tracing\OpenTelemetry;

use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use Symfony\Contracts\Service\ResetInterface;
use Vortos\Tracing\Contract\SpanInterface;
use Vortos\Tracing\Contract\TracingInterface;

/**
 * OpenTelemetry implementation of TracingInterface.
 * 
 * Bridges the framework's tracing abstraction to the OpenTelemetry SDK.
 * Requires open-telemetry/api and open-telemetry/sdk.
 * 
 * injectHeaders() writes W3C traceparent/tracestate into outgoing Kafka message
 * headers so consumer spans are correctly parented across service boundaries.
 * 
 * extractContext() restores the incoming trace context from message headers,
 * making all spans created during message processing children of the producer span.
 * 
 * currentCorrelationId() returns the active OTel trace ID, unifying correlation IDs
 * with distributed traces — the same ID appears in logs, Kafka headers, and the tracing UI.
 */
final class OpenTelemetryTracer implements TracingInterface, ResetInterface
{
    private array $scopes = [];
    /** @var array<string, string> */
    private array $baggage = [];

    /**
     * Inbound baggage keys accepted from external requests.
     * Any key not on this list is discarded to prevent trace-context poisoning.
     */
    private const ALLOWED_BAGGAGE_KEYS = [
        'correlation_id',
        'request_id',
        'tenant_id',
        'session_id',
    ];

    public function __construct(
        private TracerInterface $tracer,
        private TextMapPropagatorInterface $propagator,
        private mixed $shutdown = null,
        /** @var list<string> */
        private array $allowedBaggageKeys = self::ALLOWED_BAGGAGE_KEYS,
    ){
    }

    public function startSpan(string $name, array $attributes = []): SpanInterface
    {
        $otelSpan = $this->tracer->spanBuilder($name)->setAttributes($attributes)->startSpan();
        $scope = $otelSpan->activate();
        $scopeId = spl_object_id($scope);
        $this->scopes[$scopeId] = $scope;

        return new OpenTelemetrySpan(
            $otelSpan,
            $scope,
            function () use ($scopeId): void {
                unset($this->scopes[$scopeId]);
            },
        );
    }

    public function injectHeaders(array &$headers): void
    {
        $this->propagator->inject($headers);
        if ($this->baggage !== []) {
            $headers['baggage'] = implode(',', array_map(
                static fn (string $key, string $value): string => rawurlencode($key) . '=' . rawurlencode($value),
                array_keys($this->baggage),
                $this->baggage,
            ));
        }
    }

    public function extractContext(array $headers): void
    {
        $context = $this->propagator->extract($headers);
        $scope = Context::storage()->attach($context);
        $this->scopes[spl_object_id($scope)] = $scope;

        $baggage = $headers['baggage'] ?? $headers['Baggage'] ?? null;
        if (is_array($baggage)) {
            $baggage = $baggage[0] ?? null;
        }
        if (is_string($baggage)) {
            $this->extractBaggage($baggage);
        }
    }

    public function setBaggageItem(string $key, string $value): void
    {
        if (!preg_match('/^[a-zA-Z0-9_.-]{1,64}$/', $key)
            || preg_match('/[\r\n,=;]/', $value)
            || strlen($value) > 256
        ) {
            throw new \InvalidArgumentException('Invalid tracing baggage item.');
        }

        $this->baggage[$key] = $value;
    }

    public function baggageItem(string $key): ?string
    {
        return $this->baggage[$key] ?? null;
    }

    public function baggage(): array
    {
        return $this->baggage;
    }

    public function currentCorrelationId(): ?string
    {
        $context = Span::getCurrent()->getContext();

        if(!$context->isValid()){
            return null;
        }

        return $context->getTraceId();
    }

    public function reset(): void
    {
        foreach (array_reverse($this->scopes) as $scope) {
            $scope->detach();
        }

        $this->scopes = [];
        $this->baggage = [];

        if (is_callable($this->shutdown)) {
            ($this->shutdown)();
        }
    }

    private function extractBaggage(string $header): void
    {
        foreach (explode(',', $header) as $member) {
            [$key, $value] = array_pad(explode('=', trim($member), 2), 2, '');
            $key = rawurldecode($key);
            $value = rawurldecode($value);
            if ($key !== ''
                && preg_match('/^[a-zA-Z0-9_.-]{1,64}$/', $key)
                && strlen($value) <= 256
                && in_array($key, $this->allowedBaggageKeys, true)
            ) {
                $this->baggage[$key] = $value;
            }
        }
    }
}
