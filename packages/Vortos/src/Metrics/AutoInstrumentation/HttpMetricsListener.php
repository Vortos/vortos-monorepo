<?php

declare(strict_types=1);

namespace Vortos\Metrics\AutoInstrumentation;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;
use Vortos\Observability\Config\ObservabilityModule;
use Vortos\Observability\Telemetry\FrameworkMetric;
use Vortos\Observability\Telemetry\FrameworkMetricLabels;
use Vortos\Observability\Telemetry\MetricLabel;
use Vortos\Observability\Telemetry\MetricLabelValue;
use Vortos\Observability\Telemetry\TelemetryRequestAttributes;

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
    public function __construct(private readonly FrameworkTelemetry $telemetry) {}

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
        $blockedReason = $request->attributes->get(TelemetryRequestAttributes::BLOCKED_REASON);

        $requestLabels = FrameworkMetricLabels::of(
            MetricLabelValue::of(MetricLabel::Method, $method),
            MetricLabelValue::of(MetricLabel::Route, $route),
            MetricLabelValue::of(MetricLabel::Status, $status),
        );
        $durationLabels = FrameworkMetricLabels::of(
            MetricLabelValue::of(MetricLabel::Method, $method),
            MetricLabelValue::of(MetricLabel::Route, $route),
        );

        $this->telemetry->increment(ObservabilityModule::Http, FrameworkMetric::HttpRequestsTotal, $requestLabels);

        if (is_string($blockedReason) || $status === '404') {
            $this->telemetry->increment(
                ObservabilityModule::Http,
                FrameworkMetric::HttpBlockedTotal,
                FrameworkMetricLabels::of(
                    MetricLabelValue::of(MetricLabel::Reason, is_string($blockedReason) ? $blockedReason : 'not_found'),
                    MetricLabelValue::of(MetricLabel::Status, $status),
                ),
            );
        }

        if ($start !== null) {
            $durationMs = (hrtime(true) - $start) / 1_000_000;

            $this->telemetry->observe(ObservabilityModule::Http, FrameworkMetric::HttpRequestDurationMs, $durationLabels, $durationMs);
        }
    }
}
