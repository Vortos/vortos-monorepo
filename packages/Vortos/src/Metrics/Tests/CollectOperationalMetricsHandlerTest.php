<?php

declare(strict_types=1);

namespace Vortos\Metrics\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Vortos\Metrics\Contract\CounterInterface;
use Vortos\Metrics\Contract\FlushableMetricsInterface;
use Vortos\Metrics\Contract\GaugeInterface;
use Vortos\Metrics\Contract\HistogramInterface;
use Vortos\Metrics\Contract\MetricsCollectorInterface;
use Vortos\Metrics\Contract\MetricsInterface;
use Vortos\Metrics\Schedule\CollectOperationalMetricsCommand;
use Vortos\Metrics\Schedule\CollectOperationalMetricsHandler;

final class CollectOperationalMetricsHandlerTest extends TestCase
{
    public function test_runs_every_collector_then_flushes(): void
    {
        $first  = new SpyCollector();
        $second = new SpyCollector();
        $metrics = new FlushSpyMetrics();

        (new CollectOperationalMetricsHandler([$first, $second], $metrics))(
            new CollectOperationalMetricsCommand(),
        );

        self::assertSame(1, $first->collectCalls);
        self::assertSame(1, $second->collectCalls);
        self::assertSame(1, $metrics->flushCalls, 'Push adapters buffer until flush; without it the sample never leaves the process.');
    }

    public function test_a_failing_collector_does_not_abort_its_siblings_or_the_fire(): void
    {
        $failing   = new SpyCollector(new RuntimeException('database is unreachable'));
        $healthy   = new SpyCollector();
        $metrics   = new FlushSpyMetrics();

        (new CollectOperationalMetricsHandler([$failing, $healthy], $metrics))(
            new CollectOperationalMetricsCommand(),
        );

        self::assertSame(1, $healthy->collectCalls, 'A collector failing must not starve the ones after it.');
        self::assertSame(1, $metrics->flushCalls, 'Whatever did collect still has to be exported.');
    }

    public function test_non_flushable_metrics_adapter_is_tolerated(): void
    {
        $collector = new SpyCollector();

        (new CollectOperationalMetricsHandler([$collector], new NonFlushableMetrics()))(
            new CollectOperationalMetricsCommand(),
        );

        self::assertSame(1, $collector->collectCalls);
    }
}

final class SpyCollector implements MetricsCollectorInterface
{
    public int $collectCalls = 0;

    public function __construct(private readonly ?\Throwable $throw = null) {}

    public function collect(): void
    {
        $this->collectCalls++;

        if ($this->throw !== null) {
            throw $this->throw;
        }
    }
}

class NonFlushableMetrics implements MetricsInterface
{
    public function counter(string $name, array $labels = []): CounterInterface
    {
        return new class implements CounterInterface {
            public function increment(float $by = 1.0): void {}
        };
    }

    public function gauge(string $name, array $labels = []): GaugeInterface
    {
        return new class implements GaugeInterface {
            public function set(float $value): void {}
            public function increment(float $by = 1.0): void {}
            public function decrement(float $by = 1.0): void {}
        };
    }

    public function histogram(string $name, array $labels = []): HistogramInterface
    {
        return new class implements HistogramInterface {
            public function observe(float $value): void {}
        };
    }
}

final class FlushSpyMetrics extends NonFlushableMetrics implements FlushableMetricsInterface
{
    public int $flushCalls = 0;

    public function flush(): void
    {
        $this->flushCalls++;
    }
}
