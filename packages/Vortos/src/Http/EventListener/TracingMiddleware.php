<?php

declare(strict_types=1);

namespace Vortos\Http\EventListener;

use Vortos\Http\Attribute\AsMiddleware;
use Vortos\Http\Contract\MiddlewareInterface;
use Vortos\Http\MiddlewareOrder;
use Vortos\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Vortos\Observability\Config\ObservabilityModule;
use Vortos\Observability\Telemetry\TelemetryRequestAttributes;
use Vortos\Tracing\Attribute\DisableTracing;
use Vortos\Tracing\Attribute\TraceWith;
use Vortos\Tracing\Contract\SpanInterface;
use Vortos\Tracing\Contract\TracingInterface;

/**
 * Creates an HTTP root span for every incoming request.
 *
 * ## Trace context propagation
 *
 *   Incoming: extracts W3C traceparent/tracestate headers from the request,
 *             continuing a distributed trace started by an upstream service.
 *             Only performed when $trustRemoteContext = true (set via VortosTracingConfig).
 *             In production, trust only if requests come exclusively from internal services
 *             behind a network boundary — an external caller with a crafted traceparent
 *             header can otherwise inject arbitrary trace IDs into your backend.
 *   Outgoing: injects the current trace context into response headers so
 *             downstream services and the browser can carry the trace forward.
 *
 * ## Span lifecycle
 *
 *   Before $next: [extractContext if trusted] + startSpan
 *   After $next:  addAttribute(status_code) + injectHeaders + end()
 *   On exception: recordException + setStatus('error') + end() + rethrow
 *
 * ## http.url — path only, never the full URI
 *
 *   The span stores getSchemeAndHttpHost() + getPathInfo() — the query string is
 *   intentionally omitted. Query strings can carry tokens, API keys, and PII
 *   (e.g. ?reset_token=abc). These must never land in a trace backend.
 *
 * ## NoOp safety
 *
 *   When TracingInterface is the default NoOpTracer, every method is a no-op.
 */
#[AsMiddleware(order: MiddlewareOrder::OUTERMOST)]
final class TracingMiddleware implements MiddlewareInterface
{
    private const SPAN_ATTRIBUTE  = '_vortos_span';
    private const START_ATTRIBUTE = '_vortos_trace_start';

    public function __construct(
        private readonly TracingInterface $tracer,
        private readonly bool $trustRemoteContext = false,
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        if ($request->attributes->get(TelemetryRequestAttributes::DROP_TRACE) === true) {
            return $next($request);
        }

        // Extract W3C trace context from upstream (only when explicitly trusted)
        if ($this->trustRemoteContext) {
            $this->tracer->extractContext($request->headers->all());
        }

        $request->attributes->set(self::START_ATTRIBUTE, hrtime(true));

        // Resolve controller attributes — kernel stored the callable before pipeline runs
        $callable  = $request->attributes->get('_controller_callable');
        $traceWith = $callable !== null ? $this->traceWith($callable) : null;

        if ($callable !== null && $this->hasAttribute($callable, DisableTracing::class)) {
            return $next($request);
        }

        $name = $traceWith !== null && $traceWith->spanName !== ''
            ? $traceWith->spanName
            : 'http.' . $request->getMethod();

        $span = $this->tracer->startSpan($name, [
            'http.method'              => $request->getMethod(),
            'http.url'                 => $request->getSchemeAndHttpHost() . $request->getPathInfo(),
            'http.route'               => $request->attributes->get('_route', 'unknown'),
            'http.scheme'              => $request->getScheme(),
            'http.host'                => $request->getHost(),
            'vortos.module'            => ObservabilityModule::Http,
            'vortos.trace.sample_rate' => $traceWith?->sampleRate,
        ]);

        $request->attributes->set(self::SPAN_ATTRIBUTE, $span);

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus('error');
            $span->end();
            throw $e;
        }

        $status = $response->getStatusCode();

        if ($status === 404) {
            $span->addAttribute('vortos.trace.dropped', true);
            $span->setStatus('ok');
            $span->end();
            return $response;
        }

        $span->addAttribute('http.status_code', $status);

        $start = $request->attributes->get(self::START_ATTRIBUTE);
        if (is_int($start)) {
            $span->addAttribute('http.server.duration_ms', (hrtime(true) - $start) / 1_000_000);
        }

        $span->setStatus($status >= 500 ? 'error' : 'ok');

        $headers = [];
        $this->tracer->injectHeaders($headers);
        foreach ($headers as $headerName => $value) {
            $response->headers->set($headerName, $value);
        }

        $span->end();

        return $response;
    }

    private function hasAttribute(mixed $controller, string $attributeClass): bool
    {
        return $this->controllerAttribute($controller, $attributeClass) !== null;
    }

    private function traceWith(mixed $controller): ?TraceWith
    {
        $attribute = $this->controllerAttribute($controller, TraceWith::class);
        return $attribute instanceof TraceWith ? $attribute : null;
    }

    private function controllerAttribute(mixed $controller, string $attributeClass): ?object
    {
        if (is_array($controller) && isset($controller[0], $controller[1])) {
            $class  = is_object($controller[0]) ? $controller[0]::class : (string) $controller[0];
            $method = (string) $controller[1];
        } elseif (is_object($controller) && method_exists($controller, '__invoke')) {
            $class  = $controller::class;
            $method = '__invoke';
        } else {
            return null;
        }

        $methodReflection = new \ReflectionMethod($class, $method);
        $methodAttributes = $methodReflection->getAttributes($attributeClass);
        if ($methodAttributes !== []) {
            return $methodAttributes[0]->newInstance();
        }

        $classAttributes = (new \ReflectionClass($class))->getAttributes($attributeClass);
        return $classAttributes !== [] ? $classAttributes[0]->newInstance() : null;
    }
}
