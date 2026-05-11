<?php

declare(strict_types=1);

namespace Vortos\Metrics\AutoInstrumentation;

use Vortos\Metrics\Definition\MetricDefinition;
use Vortos\Metrics\Definition\MetricDefinitionProviderInterface;

final class CqrsMetricDefinitions implements MetricDefinitionProviderInterface
{
    private const DURATION_BUCKETS_MS = [1, 5, 10, 25, 50, 100, 250, 500, 1000, 2500];

    public function definitions(): array
    {
        return [
            MetricDefinition::counter(
                'cqrs_commands_total',
                'Total CQRS commands dispatched by command class.',
                ['command'],
            ),
            MetricDefinition::counter(
                'cqrs_command_failures_total',
                'Total CQRS command failures by command class.',
                ['command'],
            ),
            MetricDefinition::histogram(
                'cqrs_command_duration_ms',
                'CQRS command handling duration in milliseconds by command class.',
                ['command'],
                self::DURATION_BUCKETS_MS,
            ),
        ];
    }
}
