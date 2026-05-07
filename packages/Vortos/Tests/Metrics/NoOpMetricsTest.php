<?php

declare(strict_types=1);

namespace Vortos\Tests\Metrics;

use PHPUnit\Framework\TestCase;
use Vortos\Metrics\Adapter\NoOpMetrics;
use Vortos\Metrics\Instrument\NoOpCounter;
use Vortos\Metrics\Instrument\NoOpGauge;
use Vortos\Metrics\Instrument\NoOpHistogram;

final class NoOpMetricsTest extends TestCase
{
    private NoOpMetrics $metrics;

    protected function setUp(): void
    {
        $this->metrics = new NoOpMetrics();
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
        $histogram = $this->metrics->histogram('test_histogram', [10, 50, 100], ['label' => 'value']);
        $this->assertInstanceOf(NoOpHistogram::class, $histogram);
    }

    public function test_counter_increment_is_silent(): void
    {
        $this->expectNotToPerformAssertions();
        $this->metrics->counter('noop', [])->increment();
        $this->metrics->counter('noop', [])->increment(5.0);
    }

    public function test_gauge_operations_are_silent(): void
    {
        $this->expectNotToPerformAssertions();
        $gauge = $this->metrics->gauge('noop', []);
        $gauge->set(42.0);
        $gauge->increment();
        $gauge->decrement(3.0);
    }

    public function test_histogram_observe_is_silent(): void
    {
        $this->expectNotToPerformAssertions();
        $this->metrics->histogram('noop', [], [])->observe(123.456);
    }
}
