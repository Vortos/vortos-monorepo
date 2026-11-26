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
        $this->telemetry->increment(
            ObservabilityModule::Http,
            FrameworkMetric::HttpClientAddressTotal,
            FrameworkMetricLabels::of(MetricLabelValue::of(MetricLabel::Result, self::clientAddressResolution($request))),
        );

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

    /**
     * How getClientIp() arrived at its answer for this request.
     *
     * Behind a reverse proxy every request carries X-Forwarded-For. When the proxy is not among the
     * trusted proxies, Symfony ignores the header and getClientIp() is the proxy's own address — for
     * every client at once: per-address rate limits become one platform-wide bucket, and sessions and
     * audit records all name the proxy. Nothing fails, so nothing else notices. Counting the outcome
     * (the healthy results too, so an absent series is not mistaken for a clean one) turns that silent
     * misconfiguration into a ratio an alert can watch.
     *
     * @return 'forwarded'|'direct'|'untrusted_forwarder'
     */
    private static function clientAddressResolution(Request $request): string
    {
        if (!$request->headers->has('X-Forwarded-For')) {
            return 'direct';
        }

        return $request->isFromTrustedProxy() ? 'forwarded' : 'untrusted_forwarder';
    }
}
