<?php

declare(strict_types=1);

namespace Vortos\Metrics\AutoInstrumentation;

use Vortos\Metrics\Definition\MetricDefinition;
use Vortos\Metrics\Definition\MetricDefinitionProviderInterface;

final class HttpMetricDefinitions implements MetricDefinitionProviderInterface
{
    private const DURATION_BUCKETS_MS = [5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000];

    public function definitions(): array
    {
        return [
            MetricDefinition::counter(
                'http_requests_total',
                'Total HTTP responses grouped by method, route, and status code.',
                ['method', 'route', 'status'],
            ),
            MetricDefinition::histogram(
                'http_request_duration_ms',
                'HTTP request duration in milliseconds grouped by method and route.',
                ['method', 'route'],
                self::DURATION_BUCKETS_MS,
            ),
            MetricDefinition::counter(
                'http_blocked_total',
                'HTTP requests dropped from tracing because they were blocked or low-value noise.',
                ['reason', 'status'],
            ),
            MetricDefinition::counter(
                'http_client_address_total',
                'HTTP requests by how the client address was resolved: forwarded (by a trusted proxy), '
                . 'direct (no forwarding header), or untrusted_forwarder (a forwarding header from a peer '
                . 'that is not a trusted proxy, so the address is the peer\'s). Any sustained '
                . 'untrusted_forwarder share behind a proxy means trusted_proxies no longer names it.',
                ['result'],
            ),
        ];
    }
}
