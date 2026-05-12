<?php

declare(strict_types=1);

namespace Vortos\Metrics\AutoInstrumentation;

use Vortos\Metrics\Definition\MetricDefinition;
use Vortos\Metrics\Definition\MetricDefinitionProviderInterface;

final class SecurityMetricDefinitions implements MetricDefinitionProviderInterface
{
    public function definitions(): array
    {
        return [
            MetricDefinition::counter(
                'security_events_total',
                'Total security events grouped by low-cardinality event name.',
                ['event'],
            ),
            MetricDefinition::counter(
                'rate_limit_allowed_total',
                'Total requests allowed by rate limit enforcement.',
                ['policy', 'scope', 'controller'],
            ),
            MetricDefinition::counter(
                'rate_limit_blocked_total',
                'Total requests blocked by rate limit enforcement.',
                ['policy', 'scope', 'controller'],
            ),
            MetricDefinition::counter(
                'quota_allowed_total',
                'Total requests allowed by quota enforcement.',
                ['quota', 'bucket', 'period', 'controller'],
            ),
            MetricDefinition::counter(
                'quota_blocked_total',
                'Total requests blocked by quota enforcement.',
                ['quota', 'bucket', 'period', 'controller'],
            ),
            MetricDefinition::counter(
                'quota_consumed_total',
                'Total quota units consumed.',
                ['quota', 'bucket', 'period', 'controller'],
            ),
            MetricDefinition::counter(
                'feature_access_allowed_total',
                'Total requests allowed by feature access enforcement.',
                ['feature', 'policy', 'controller'],
            ),
            MetricDefinition::counter(
                'feature_access_denied_total',
                'Total requests denied by feature access enforcement.',
                ['feature', 'policy', 'controller'],
            ),
        ];
    }
}
