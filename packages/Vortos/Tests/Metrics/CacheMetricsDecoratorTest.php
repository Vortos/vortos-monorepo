<?php

declare(strict_types=1);

namespace Vortos\Tests\Metrics;

use PHPUnit\Framework\TestCase;
use Vortos\Cache\Adapter\InMemoryAdapter;
use Vortos\Metrics\AutoInstrumentation\CacheMetricsDecorator;
use Vortos\Metrics\Contract\CounterInterface;
use Vortos\Metrics\Contract\MetricsInterface;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;

final class CacheMetricsDecoratorTest extends TestCase
{
    private InMemoryAdapter $inner;

    protected function setUp(): void
    {
        $this->inner = new InMemoryAdapter();
    }

    public function test_set_nx_returns_true_when_key_is_new(): void
    {
        $decorator = $this->makeDecorator();

        $this->assertTrue($decorator->setNx('key', 'value', 60));
    }

    public function test_set_nx_returns_false_when_key_exists(): void
    {
        $this->inner->set('key', 'existing', 60);
        $decorator = $this->makeDecorator();

        $this->assertFalse($decorator->setNx('key', 'new', 60));
    }

    public function test_set_nx_records_set_ok_metric_on_successful_write(): void
    {
        $counter = $this->createMock(CounterInterface::class);
        $counter->expects($this->once())->method('increment')->with(1.0);

        $metrics = $this->createMock(MetricsInterface::class);
        $metrics->expects($this->once())
            ->method('counter')
            ->with('cache_operations_total', ['operation' => 'set', 'result' => 'ok'])
            ->willReturn($counter);

        $decorator = new CacheMetricsDecorator($this->inner, new FrameworkTelemetry($metrics));
        $decorator->setNx('key', 'value', 60);
    }

    public function test_set_nx_records_set_miss_metric_when_key_already_exists(): void
    {
        $this->inner->set('key', 'existing', 60);

        $counter = $this->createMock(CounterInterface::class);
        $counter->expects($this->once())->method('increment')->with(1.0);

        $metrics = $this->createMock(MetricsInterface::class);
        $metrics->expects($this->once())
            ->method('counter')
            ->with('cache_operations_total', ['operation' => 'set', 'result' => 'miss'])
            ->willReturn($counter);

        $decorator = new CacheMetricsDecorator($this->inner, new FrameworkTelemetry($metrics));
        $decorator->setNx('key', 'new', 60);
    }

    public function test_set_nx_does_not_overwrite_existing_value(): void
    {
        $this->inner->set('key', 'original', 60);
        $decorator = $this->makeDecorator();
        $decorator->setNx('key', 'overwrite', 60);

        $this->assertSame('original', $this->inner->get('key'));
    }

    public function test_implements_atomic_cache_interface(): void
    {
        $decorator = $this->makeDecorator();

        $this->assertInstanceOf(\Vortos\Cache\Contract\AtomicCacheInterface::class, $decorator);
    }

    private function makeDecorator(): CacheMetricsDecorator
    {
        $counter = $this->createStub(CounterInterface::class);
        $metrics = $this->createStub(MetricsInterface::class);
        $metrics->method('counter')->willReturn($counter);

        return new CacheMetricsDecorator($this->inner, new FrameworkTelemetry($metrics));
    }
}
