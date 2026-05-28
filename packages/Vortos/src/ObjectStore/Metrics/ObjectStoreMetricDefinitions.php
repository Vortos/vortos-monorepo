<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Metrics;

use Vortos\Metrics\Definition\MetricDefinition;
use Vortos\Metrics\Definition\MetricDefinitionProviderInterface;

final class ObjectStoreMetricDefinitions implements MetricDefinitionProviderInterface
{
    public function definitions(): array
    {
        return [
            MetricDefinition::counter(
                'vortos_object_store_operations_total',
                'Total object-store operations.',
                ['operation', 'status'],
            ),
            MetricDefinition::histogram(
                'vortos_object_store_operation_duration_ms',
                'Object-store operation duration in milliseconds.',
                ['operation'],
                [1, 5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000],
            ),
            MetricDefinition::counter(
                'vortos_object_store_lifecycle_operations_total',
                'Total object-store lifecycle provisioning operations.',
                ['operation', 'status'],
            ),
            MetricDefinition::histogram(
                'vortos_object_store_lifecycle_operation_duration_ms',
                'Object-store lifecycle provisioning operation duration in milliseconds.',
                ['operation'],
                [1, 5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000],
            ),
        ];
    }
}
