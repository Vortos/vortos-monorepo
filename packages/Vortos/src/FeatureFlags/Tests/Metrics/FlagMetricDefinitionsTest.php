<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Metrics;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\Metrics\FlagEvaluationMetrics;
use Vortos\FeatureFlags\Metrics\FlagMetricDefinitions;
use Vortos\Metrics\Definition\MetricDefinitionProviderInterface;

final class FlagMetricDefinitionsTest extends TestCase
{
    private array $definitions;

    protected function setUp(): void
    {
        $this->definitions = [];
        foreach ((new FlagMetricDefinitions())->definitions() as $def) {
            $this->definitions[$def->toArray()['name']] = $def->toArray();
        }
    }

    public function test_implements_provider_interface(): void
    {
        $this->assertInstanceOf(MetricDefinitionProviderInterface::class, new FlagMetricDefinitions());
    }

    public function test_declares_all_four_metric_names(): void
    {
        $this->assertArrayHasKey(FlagEvaluationMetrics::EVALUATIONS, $this->definitions);
        $this->assertArrayHasKey(FlagEvaluationMetrics::VARIANT_ASSIGNMENTS, $this->definitions);
        $this->assertArrayHasKey(FlagEvaluationMetrics::EVAL_DURATION_MS, $this->definitions);
        $this->assertArrayHasKey(FlagEvaluationMetrics::EXPOSURES, $this->definitions);
    }

    public function test_label_keys_match_cardinality_contract(): void
    {
        $this->assertSame(['flag', 'result'],   $this->definitions[FlagEvaluationMetrics::EVALUATIONS]['label_names']);
        $this->assertSame(['flag', 'variant'],  $this->definitions[FlagEvaluationMetrics::VARIANT_ASSIGNMENTS]['label_names']);
        $this->assertSame(['operation'],        $this->definitions[FlagEvaluationMetrics::EVAL_DURATION_MS]['label_names']);
        $this->assertSame(['flag', 'variant'],  $this->definitions[FlagEvaluationMetrics::EXPOSURES]['label_names']);
    }

    public function test_evaluation_duration_is_histogram_with_buckets(): void
    {
        $def = $this->definitions[FlagEvaluationMetrics::EVAL_DURATION_MS];
        $this->assertSame('histogram', $def['type']);
        $this->assertNotEmpty($def['buckets'], 'Histogram must declare at least one bucket');
    }

    public function test_counters_have_no_buckets(): void
    {
        foreach ([
            FlagEvaluationMetrics::EVALUATIONS,
            FlagEvaluationMetrics::VARIANT_ASSIGNMENTS,
            FlagEvaluationMetrics::EXPOSURES,
        ] as $name) {
            $this->assertSame('counter', $this->definitions[$name]['type']);
            $this->assertSame([], $this->definitions[$name]['buckets']);
        }
    }

    public function test_all_metrics_have_non_empty_help_text(): void
    {
        foreach ($this->definitions as $name => $def) {
            $this->assertNotEmpty($def['help'], "Metric '$name' has empty help text");
        }
    }
}
