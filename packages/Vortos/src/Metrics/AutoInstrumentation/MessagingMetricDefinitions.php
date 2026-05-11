<?php

declare(strict_types=1);

namespace Vortos\Metrics\AutoInstrumentation;

use Vortos\Metrics\Definition\MetricDefinition;
use Vortos\Metrics\Definition\MetricDefinitionProviderInterface;

final class MessagingMetricDefinitions implements MetricDefinitionProviderInterface
{
    private const DURATION_BUCKETS_MS = [1, 5, 10, 25, 50, 100, 250, 500, 1000];

    public function definitions(): array
    {
        return [
            MetricDefinition::counter(
                'messaging_events_dispatched_total',
                'Total domain events dispatched by event class.',
                ['event'],
            ),
            MetricDefinition::counter(
                'messaging_event_failures_total',
                'Total domain event dispatch failures by event class.',
                ['event'],
            ),
            MetricDefinition::histogram(
                'messaging_event_duration_ms',
                'Domain event dispatch duration in milliseconds by event class.',
                ['event'],
                self::DURATION_BUCKETS_MS,
            ),
        ];
    }
}
