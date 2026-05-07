<?php

declare(strict_types=1);

namespace Vortos\Metrics\AutoInstrumentation;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Vortos\Metrics\Contract\MetricsInterface;

/**
 * Records HTTP request metrics automatically.
 *
 * ## Metrics recorded
 *
 *   vortos_http_requests_total{method, route, status} — counter
 *   vortos_http_request_duration_ms{method, route}    — histogram
 *
 * ## High-cardinality warning
 *
 * 'route' uses the named route (e.g. 'user.register'), NOT the raw URL.
 * Never use request URI as a label — it creates unbounded cardinality.
 * If _route is not set (e.g. 404 requests), label value is 'unknown'.
 *
 * ## Timing
 *
 * Request start time is stored as a request attribute (_vortos_metrics_start).
 * ResponseEvent computes elapsed time in milliseconds and observes it.
 */
final class HttpMetricsListener implements EventSubscriberInterface
{
    private const DURATION_BUCKETS = [5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000];

    public function __construct(private readonly MetricsInterface $metrics) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST  => ['onRequest', 250],
            KernelEvents::RESPONSE => ['onResponse', -10],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $event->getRequest()->attributes->set('_vortos_metrics_start', hrtime(true));
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $start   = $request->attributes->get('_vortos_metrics_start');

        $method = $request->getMethod();
        $route  = $request->attributes->get('_route', 'unknown');
        $status = (string) $event->getResponse()->getStatusCode();

        $this->metrics->counter('http_requests_total', [
            'method' => $method,
            'route'  => $route,
            'status' => $status,
        ])->increment();

        if ($start !== null) {
            $durationMs = (hrtime(true) - $start) / 1_000_000;

            $this->metrics->histogram('http_request_duration_ms', self::DURATION_BUCKETS, [
                'method' => $method,
                'route'  => $route,
            ])->observe($durationMs);
        }
    }
}
