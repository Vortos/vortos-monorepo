<?php

declare(strict_types=1);

namespace Vortos\Metrics\AutoInstrumentation;

use Vortos\Metrics\Definition\MetricDefinition;
use Vortos\Metrics\Definition\MetricDefinitionProviderInterface;

final class CacheMetricDefinitions implements MetricDefinitionProviderInterface
{
    public function definitions(): array
    {
        return [
            MetricDefinition::counter(
                'cache_operations_total',
                'Total cache operations grouped by operation and result.',
                ['operation', 'result'],
            ),
        ];
    }
}
