<?php

declare(strict_types=1);

namespace Vortos\Metrics\AutoInstrumentation;

use Vortos\Metrics\Definition\MetricDefinition;
use Vortos\Metrics\Definition\MetricDefinitionProviderInterface;

final class PersistenceMetricDefinitions implements MetricDefinitionProviderInterface
{
    private const DURATION_BUCKETS_MS = [1, 5, 10, 25, 50, 100, 250, 500, 1000, 2500];

    public function definitions(): array
    {
        return [
            MetricDefinition::counter(
                'db_queries_total',
                'Total database operations grouped by driver and operation.',
                ['driver', 'operation'],
            ),
            MetricDefinition::histogram(
                'db_query_duration_ms',
                'Database operation duration in milliseconds grouped by driver.',
                ['driver'],
                self::DURATION_BUCKETS_MS,
            ),
        ];
    }
}
