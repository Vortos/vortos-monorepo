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
        ];
    }
}
