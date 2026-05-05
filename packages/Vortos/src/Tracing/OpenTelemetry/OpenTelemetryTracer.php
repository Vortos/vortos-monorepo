<?php

declare(strict_types=1);

namespace Vortos\Tracing\OpenTelemetry;

use Vortos\Tracing\Contract\SpanInterface;
use Vortos\Tracing\Contract\TracingInterface;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use Symfony\Contracts\Service\ResetInterface;

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

    public function __construct(
        private TracerInterface $tracer,
        private TextMapPropagatorInterface $propagator
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
    }

    public function extractContext(array $headers): void
    {
        $context = $this->propagator->extract($headers);
        $scope = Context::storage()->attach($context);
        $this->scopes[spl_object_id($scope)] = $scope;
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
    }
}
