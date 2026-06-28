<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Metrics;

use Vortos\Metrics\Definition\MetricDefinition;
use Vortos\Metrics\Definition\MetricDefinitionProviderInterface;

/**
 * Declares all feature-flag metric names so the {@see \Vortos\Metrics\Definition\MetricDefinitionRegistry}
 * accepts them before any recording attempt.
 *
 * Every metric recorded by {@see FlagEvaluationMetrics} must have a matching declaration here.
 * A missing declaration causes MetricNotDefinedException with any non-NoOp backend.
 */
final class FlagMetricDefinitions implements MetricDefinitionProviderInterface
{
    private const EVAL_DURATION_BUCKETS_MS = [0.1, 0.5, 1.0, 2.0, 5.0, 10.0, 25.0, 50.0, 100.0, 250.0];

    public function definitions(): array
    {
        return [
            MetricDefinition::counter(
                FlagEvaluationMetrics::EVALUATIONS,
                'Total feature flag evaluations grouped by flag name and result (on/off).',
                ['flag', 'result'],
            ),
            MetricDefinition::counter(
                FlagEvaluationMetrics::VARIANT_ASSIGNMENTS,
                'Total feature flag variant assignments grouped by flag name and variant.',
                ['flag', 'variant'],
            ),
            MetricDefinition::histogram(
                FlagEvaluationMetrics::EVAL_DURATION_MS,
                'Feature flag evaluation duration in milliseconds grouped by operation.',
                ['operation'],
                self::EVAL_DURATION_BUCKETS_MS,
            ),
            MetricDefinition::counter(
                FlagEvaluationMetrics::EXPOSURES,
                'Total feature flag SDK exposures grouped by flag name and variant.',
                ['flag', 'variant'],
            ),
        ];
    }
}
