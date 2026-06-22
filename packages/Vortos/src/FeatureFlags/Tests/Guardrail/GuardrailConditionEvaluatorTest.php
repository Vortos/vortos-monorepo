<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Guardrail;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\Guardrail\GuardrailCondition;
use Vortos\FeatureFlags\Guardrail\GuardrailConditionEvaluator;
use Vortos\FeatureFlags\Guardrail\GuardrailMetricKind;
use Vortos\FeatureFlags\Guardrail\MetricSource\InMemoryGuardrailMetricSource;

final class GuardrailConditionEvaluatorTest extends TestCase
{
    private InMemoryGuardrailMetricSource $source;
    private GuardrailConditionEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->source    = new InMemoryGuardrailMetricSource();
        $this->evaluator = new GuardrailConditionEvaluator($this->source);
    }

    public function test_leaf_breach_when_value_exceeds_threshold(): void
    {
        $this->source->set(GuardrailMetricKind::ErrorRate, 'checkout', 'production', 0.10);

        $this->assertTrue($this->eval($this->leaf(GuardrailMetricKind::ErrorRate, 0.05, 'gt')));
    }

    public function test_leaf_clear_when_value_below_threshold(): void
    {
        $this->source->set(GuardrailMetricKind::ErrorRate, 'checkout', 'production', 0.01);

        $this->assertFalse($this->eval($this->leaf(GuardrailMetricKind::ErrorRate, 0.05, 'gt')));
    }

    public function test_leaf_unknown_when_metric_missing(): void
    {
        $this->assertNull($this->eval($this->leaf(GuardrailMetricKind::ErrorRate, 0.05, 'gt')));
    }

    public function test_all_comparison_operators(): void
    {
        $this->source->set(GuardrailMetricKind::LatencyP99, 'checkout', 'production', 100.0);

        $this->assertTrue($this->eval($this->leaf(GuardrailMetricKind::LatencyP99, 50.0, 'gt')));
        $this->assertTrue($this->eval($this->leaf(GuardrailMetricKind::LatencyP99, 100.0, 'gte')));
        $this->assertFalse($this->eval($this->leaf(GuardrailMetricKind::LatencyP99, 100.0, 'gt')));
        $this->assertTrue($this->eval($this->leaf(GuardrailMetricKind::LatencyP99, 150.0, 'lt')));
        $this->assertTrue($this->eval($this->leaf(GuardrailMetricKind::LatencyP99, 100.0, 'lte')));
        $this->assertFalse($this->eval($this->leaf(GuardrailMetricKind::LatencyP99, 100.0, 'lt')));
    }

    public function test_and_group_all_breach(): void
    {
        $this->source->set(GuardrailMetricKind::ErrorRate, 'checkout', 'production', 0.10);
        $this->source->set(GuardrailMetricKind::LatencyP99, 'checkout', 'production', 500.0);

        $group = $this->group('AND', [
            $this->leaf(GuardrailMetricKind::ErrorRate, 0.05, 'gt'),
            $this->leaf(GuardrailMetricKind::LatencyP99, 300.0, 'gt'),
        ]);

        $this->assertTrue($this->eval($group));
    }

    public function test_and_group_one_clear_is_clear(): void
    {
        $this->source->set(GuardrailMetricKind::ErrorRate, 'checkout', 'production', 0.10);
        $this->source->set(GuardrailMetricKind::LatencyP99, 'checkout', 'production', 100.0);

        $group = $this->group('AND', [
            $this->leaf(GuardrailMetricKind::ErrorRate, 0.05, 'gt'),
            $this->leaf(GuardrailMetricKind::LatencyP99, 300.0, 'gt'),
        ]);

        $this->assertFalse($this->eval($group));
    }

    public function test_and_group_one_null_one_breach_is_unknown(): void
    {
        $this->source->set(GuardrailMetricKind::ErrorRate, 'checkout', 'production', 0.10);

        $group = $this->group('AND', [
            $this->leaf(GuardrailMetricKind::ErrorRate, 0.05, 'gt'),
            $this->leaf(GuardrailMetricKind::LatencyP99, 300.0, 'gt'),
        ]);

        $this->assertNull($this->eval($group));
    }

    public function test_or_group_any_breach(): void
    {
        $this->source->set(GuardrailMetricKind::ErrorRate, 'checkout', 'production', 0.01);
        $this->source->set(GuardrailMetricKind::LatencyP99, 'checkout', 'production', 500.0);

        $group = $this->group('OR', [
            $this->leaf(GuardrailMetricKind::ErrorRate, 0.05, 'gt'),
            $this->leaf(GuardrailMetricKind::LatencyP99, 300.0, 'gt'),
        ]);

        $this->assertTrue($this->eval($group));
    }

    public function test_or_group_all_clear(): void
    {
        $this->source->set(GuardrailMetricKind::ErrorRate, 'checkout', 'production', 0.01);
        $this->source->set(GuardrailMetricKind::LatencyP99, 'checkout', 'production', 100.0);

        $group = $this->group('OR', [
            $this->leaf(GuardrailMetricKind::ErrorRate, 0.05, 'gt'),
            $this->leaf(GuardrailMetricKind::LatencyP99, 300.0, 'gt'),
        ]);

        $this->assertFalse($this->eval($group));
    }

    public function test_or_group_all_null_is_unknown(): void
    {
        $group = $this->group('OR', [
            $this->leaf(GuardrailMetricKind::ErrorRate, 0.05, 'gt'),
            $this->leaf(GuardrailMetricKind::LatencyP99, 300.0, 'gt'),
        ]);

        $this->assertNull($this->eval($group));
    }

    public function test_nested_groups(): void
    {
        $this->source->set(GuardrailMetricKind::ErrorRate, 'checkout', 'production', 0.10);
        $this->source->set(GuardrailMetricKind::LatencyP99, 'checkout', 'production', 100.0);
        $this->source->set(GuardrailMetricKind::LatencyP50, 'checkout', 'production', 50.0);

        // error_rate>0.05 AND (latency_p99>300 OR latency_p50>10)
        $nested = $this->group('AND', [
            $this->leaf(GuardrailMetricKind::ErrorRate, 0.05, 'gt'),
            $this->group('OR', [
                $this->leaf(GuardrailMetricKind::LatencyP99, 300.0, 'gt'),
                $this->leaf(GuardrailMetricKind::LatencyP50, 10.0, 'gt'),
            ]),
        ]);

        $this->assertTrue($this->eval($nested));
    }

    private function eval(GuardrailCondition $c): ?bool
    {
        return $this->evaluator->evaluate($c, 'checkout', 'production', 300);
    }

    private function leaf(GuardrailMetricKind $kind, float $threshold, string $op): GuardrailCondition
    {
        return new GuardrailCondition(
            id: 'c-' . bin2hex(random_bytes(3)), combinator: null, metricKind: $kind,
            customMetricName: null, threshold: $threshold, comparisonOperator: $op, sortOrder: 0,
        );
    }

    private function group(string $combinator, array $children): GuardrailCondition
    {
        return new GuardrailCondition(
            id: 'g-' . bin2hex(random_bytes(3)), combinator: $combinator, metricKind: null,
            customMetricName: null, threshold: null, comparisonOperator: null, sortOrder: 0,
            children: $children,
        );
    }
}
