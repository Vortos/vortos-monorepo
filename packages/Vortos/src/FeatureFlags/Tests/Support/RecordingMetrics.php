<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Support;

use Vortos\Metrics\Contract\CounterInterface;
use Vortos\Metrics\Contract\GaugeInterface;
use Vortos\Metrics\Contract\HistogramInterface;
use Vortos\Metrics\Contract\MetricsInterface;

/**
 * In-memory {@see MetricsInterface} test double that records every counter increment and
 * histogram observation with its name + labels, so tests can assert what was emitted (and
 * that no high-cardinality label keys leak).
 */
final class RecordingMetrics implements MetricsInterface
{
    /** @var list<array{name:string,labels:array<string,string>,by:float}> */
    public array $counters = [];

    /** @var list<array{name:string,labels:array<string,string>,value:float}> */
    public array $histograms = [];

    public function counter(string $name, array $labels = []): CounterInterface
    {
        return new class($this, $name, $labels) implements CounterInterface {
            public function __construct(
                private RecordingMetrics $sink,
                private string $name,
                private array $labels,
            ) {}

            public function increment(float $by = 1.0): void
            {
                $this->sink->counters[] = ['name' => $this->name, 'labels' => $this->labels, 'by' => $by];
            }
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
        return new class($this, $name, $labels) implements HistogramInterface {
            public function __construct(
                private RecordingMetrics $sink,
                private string $name,
                private array $labels,
            ) {}

            public function observe(float $value): void
            {
                $this->sink->histograms[] = ['name' => $this->name, 'labels' => $this->labels, 'value' => $value];
            }
        };
    }
}
