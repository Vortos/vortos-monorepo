<?php

declare(strict_types=1);

namespace Vortos\Metrics\AutoInstrumentation;

use Vortos\Http\Attribute\AsMiddleware;
use Vortos\Http\Contract\MiddlewareInterface;
use Vortos\Http\MiddlewareOrder;
use Vortos\Http\Request;
use Symfony\Component\HttpFoundation\Response;
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
 * Runs at OUTERMOST (order 1000) — wraps the entire middleware stack so
 * duration includes all middleware processing time.
 */
#[AsMiddleware(order: MiddlewareOrder::OUTERMOST)]
final class HttpMetricsListener implements MiddlewareInterface
{
    public function __construct(private readonly FrameworkTelemetry $telemetry) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $start = hrtime(true);

        $response = $next($request);

        $method = $request->getMethod();
        $route  = $request->attributes->get('_route', 'unknown');
        $status = (string) $response->getStatusCode();
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

        $durationMs = (hrtime(true) - $start) / 1_000_000;
        $this->telemetry->observe(ObservabilityModule::Http, FrameworkMetric::HttpRequestDurationMs, $durationLabels, $durationMs);

        return $response;
    }
}
