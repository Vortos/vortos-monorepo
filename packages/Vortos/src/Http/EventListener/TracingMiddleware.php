<?php

declare(strict_types=1);

namespace Vortos\Http\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
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
 *   RequestEvent  (priority 255) → [extractContext if trusted] + startSpan
 *   ResponseEvent (priority   0) → addAttribute(status_code) + injectHeaders + end()
 *   ExceptionEvent               → recordException + setStatus('error') + end()
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
final class TracingMiddleware implements EventSubscriberInterface
{
    private const SPAN_ATTRIBUTE = '_vortos_span';

    public function __construct(
        private readonly TracingInterface $tracer,
        private readonly bool $trustRemoteContext = false,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST   => ['onRequest', 255],
            KernelEvents::RESPONSE  => ['onResponse', 0],
            KernelEvents::EXCEPTION => ['onException', 0],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // Only trust W3C traceparent from requests that are guaranteed to come from
        // internal services. Accepting it from arbitrary external callers allows
        // trace ID injection — an attacker can link their requests to legitimate traces.
        if ($this->trustRemoteContext) {
            $this->tracer->extractContext($request->headers->all());
        }

        $span = $this->tracer->startSpan(
            'http.' . $request->getMethod(),
            [
                'http.method' => $request->getMethod(),
                // Path only — query string omitted to prevent token/PII leakage into traces.
                'http.url'    => $request->getSchemeAndHttpHost() . $request->getPathInfo(),
                'http.route'  => $request->attributes->get('_route', 'unknown'),
                'http.scheme' => $request->getScheme(),
                'http.host'   => $request->getHost(),
            ],
        );

        $request->attributes->set(self::SPAN_ATTRIBUTE, $span);
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $span = $event->getRequest()->attributes->get(self::SPAN_ATTRIBUTE);

        if (!$span instanceof SpanInterface) {
            return;
        }

        $response = $event->getResponse();
        $status   = $response->getStatusCode();

        $span->addAttribute('http.status_code', $status);
        $span->setStatus($status >= 500 ? 'error' : 'ok');

        $headers = [];
        $this->tracer->injectHeaders($headers);
        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        $span->end();
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $span = $event->getRequest()->attributes->get(self::SPAN_ATTRIBUTE);

        if (!$span instanceof SpanInterface) {
            return;
        }

        $span->recordException($event->getThrowable());
        $span->setStatus('error');
        $span->end();
    }
}
