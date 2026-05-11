<?php

declare(strict_types=1);

namespace Vortos\Tests\Metrics;

use PHPUnit\Framework\TestCase;
use Vortos\Metrics\Adapter\NoOpMetrics;
use Vortos\Metrics\Definition\MetricDefinition;
use Vortos\Metrics\Definition\MetricDefinitionRegistry;
use Vortos\Metrics\Exception\MetricLabelMismatchException;
use Vortos\Metrics\Exception\MetricNotDefinedException;
use Vortos\Metrics\Instrument\NoOpCounter;
use Vortos\Metrics\Instrument\NoOpGauge;
use Vortos\Metrics\Instrument\NoOpHistogram;

final class NoOpMetricsTest extends TestCase
{
    private NoOpMetrics $metrics;

    protected function setUp(): void
    {
        $this->metrics = new NoOpMetrics(new MetricDefinitionRegistry([
            MetricDefinition::counter('test_counter', 'Test counter.', ['label']),
            MetricDefinition::gauge('test_gauge', 'Test gauge.', ['label']),
            MetricDefinition::histogram('test_histogram', 'Test histogram.', ['label'], [10, 50, 100]),
            MetricDefinition::counter('noop_counter', 'No-op counter.'),
            MetricDefinition::gauge('noop_gauge', 'No-op gauge.'),
            MetricDefinition::histogram('noop_histogram', 'No-op histogram.', [], [1, 5, 10]),
        ]));
    }

    public function test_counter_returns_no_op_counter(): void
    {
        $counter = $this->metrics->counter('test_counter', ['label' => 'value']);
        $this->assertInstanceOf(NoOpCounter::class, $counter);
    }

    public function test_gauge_returns_no_op_gauge(): void
    {
        $gauge = $this->metrics->gauge('test_gauge', ['label' => 'value']);
        $this->assertInstanceOf(NoOpGauge::class, $gauge);
    }

    public function test_histogram_returns_no_op_histogram(): void
    {
        $histogram = $this->metrics->histogram('test_histogram', ['label' => 'value']);
        $this->assertInstanceOf(NoOpHistogram::class, $histogram);
    }

    public function test_counter_increment_is_silent(): void
    {
        $this->expectNotToPerformAssertions();
        $this->metrics->counter('noop_counter')->increment();
        $this->metrics->counter('noop_counter')->increment(5.0);
    }

    public function test_gauge_operations_are_silent(): void
    {
        $this->expectNotToPerformAssertions();
        $gauge = $this->metrics->gauge('noop_gauge');
        $gauge->set(42.0);
        $gauge->increment();
        $gauge->decrement(3.0);
    }

    public function test_histogram_observe_is_silent(): void
    {
        $this->expectNotToPerformAssertions();
        $this->metrics->histogram('noop_histogram')->observe(123.456);
    }

    public function test_undefined_metric_throws(): void
    {
        $this->expectException(MetricNotDefinedException::class);
        $this->metrics->counter('missing_total');
    }

    public function test_label_mismatch_throws(): void
    {
        $this->expectException(MetricLabelMismatchException::class);
        $this->metrics->counter('test_counter', ['other' => 'value']);
    }
}
