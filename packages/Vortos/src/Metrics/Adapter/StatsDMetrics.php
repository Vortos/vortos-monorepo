<?php

declare(strict_types=1);

namespace Vortos\Metrics\Adapter;

use Vortos\Metrics\Contract\CounterInterface;
use Vortos\Metrics\Contract\FlushableMetricsInterface;
use Vortos\Metrics\Contract\GaugeInterface;
use Vortos\Metrics\Contract\HistogramInterface;
use Vortos\Metrics\Contract\MetricsInterface;
use Vortos\Metrics\Definition\MetricDefinitionRegistry;
use Vortos\Metrics\Definition\MetricType;
use Vortos\Metrics\Instrument\StatsDCounter;
use Vortos\Metrics\Instrument\StatsDGauge;
use Vortos\Metrics\Instrument\StatsDHistogram;

/**
 * StatsD metrics adapter — buffered UDP datagrams, zero dependencies.
 *
 * Compatible with: StatsD, Telegraf (statsd_input), Datadog DogStatsD,
 * Graphite with statsd frontend, and any other UDP-based metrics aggregator.
 *
 * ## Batching
 *
 * Metrics are buffered in-memory and flushed as a single UDP packet when the
 * buffer reaches the UDP MTU (1400 bytes) or when flush() is called.
 * This reduces syscall count from O(metrics/request) to O(1/request).
 * StatsDFlushListener calls flush() on kernel.terminate so no metrics are lost.
 *
 * ## UDP fire-and-forget
 *
 * UDP is connectionless and non-blocking. If the StatsD server is unavailable,
 * packets are silently dropped. Metrics never affect application latency.
 *
 * ## Tag format
 *
 * Uses Datadog DogStatsD tag extension: |#key:value,key:value
 */
final class StatsDMetrics implements MetricsInterface, FlushableMetricsInterface
{
    private const UDP_MTU = 1400;

    /** @var resource|null */
    private mixed $socket;
    private string $buffer = '';

    public function __construct(
        private readonly MetricDefinitionRegistry $definitions,
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 8125,
        private readonly string $namespace = 'vortos',
        private readonly float $sampleRate = 1.0,
    ) {
        $socket = @fsockopen('udp://' . $host, $port, $errno, $errstr, 0);
        $this->socket = $socket !== false ? $socket : null;
    }

    public function counter(string $name, array $labels = []): CounterInterface
    {
        $definition = $this->definitions->requireType($name, MetricType::Counter);
        $orderedLabels = $this->definitions->validateLabels($definition, $labels);

        return new StatsDCounter($this->metricName($name), $orderedLabels, $this->send(...), $this->sampleRate);
    }

    public function gauge(string $name, array $labels = []): GaugeInterface
    {
        $definition = $this->definitions->requireType($name, MetricType::Gauge);
        $orderedLabels = $this->definitions->validateLabels($definition, $labels);

        return new StatsDGauge($this->metricName($name), $orderedLabels, $this->send(...));
    }

    public function histogram(string $name, array $labels = []): HistogramInterface
    {
        $definition = $this->definitions->requireType($name, MetricType::Histogram);
        $orderedLabels = $this->definitions->validateLabels($definition, $labels);

        return new StatsDHistogram($this->metricName($name), $orderedLabels, $this->send(...), $this->sampleRate);
    }

    public function flush(): void
    {
        if ($this->buffer === '' || $this->socket === null) {
            return;
        }
        @fwrite($this->socket, $this->buffer);
        $this->buffer = '';
    }

    public function __destruct()
    {
        $this->flush();
    }

    private function send(string $metric): void
    {
        if ($this->socket === null) {
            return;
        }

        $line = $this->buffer === '' ? $metric : "\n" . $metric;

        if (strlen($this->buffer) + strlen($line) > self::UDP_MTU) {
            $this->flush();
            $this->buffer = $metric;
        } else {
            $this->buffer .= $line;
        }
    }

    private function metricName(string $name): string
    {
        return $this->namespace . '.' . $name;
    }
}
