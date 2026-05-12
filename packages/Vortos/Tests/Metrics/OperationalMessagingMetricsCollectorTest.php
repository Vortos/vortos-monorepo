<?php

declare(strict_types=1);

namespace Vortos\Tests\Metrics;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Vortos\Metrics\AutoInstrumentation\OperationalMessagingMetricsCollector;
use Vortos\Metrics\Contract\CounterInterface;
use Vortos\Metrics\Contract\GaugeInterface;
use Vortos\Metrics\Contract\HistogramInterface;
use Vortos\Metrics\Contract\MetricsInterface;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;

final class OperationalMessagingMetricsCollectorTest extends TestCase
{
    public function test_collects_outbox_and_dlq_backlog_without_request_path_work(): void
    {
        $connection = $this->createMock(Connection::class);
        $metrics = new RecordingMetrics();

        $connection->expects($this->exactly(4))
            ->method('fetchAllAssociative')
            ->willReturnOnConsecutiveCalls(
                [
                    ['transport_name' => 'kafka', 'status' => 'pending', 'backlog' => 12],
                    ['transport_name' => 'kafka', 'status' => 'failed', 'backlog' => 2],
                ],
                [
                    ['transport_name' => 'kafka', 'oldest_created_at' => '2026-05-12 00:00:00'],
                ],
                [
                    ['transport_name' => 'kafka', 'event_class' => 'App\\Domain\\Order\\Event\\OrderPlaced', 'backlog' => 3],
                ],
                [
                    ['transport_name' => 'kafka', 'oldest_failed_at' => '2026-05-12 00:00:00'],
                ],
            );

        $collector = new OperationalMessagingMetricsCollector($connection, new FrameworkTelemetry($metrics));
        $collector->collect();

        $this->assertSame(
            12.0,
            $metrics->gauges['outbox_backlog_size|status=pending,transport=kafka'] ?? null,
        );
        $this->assertSame(
            2.0,
            $metrics->gauges['outbox_backlog_size|status=failed,transport=kafka'] ?? null,
        );
        $this->assertSame(
            3.0,
            $metrics->gauges['dlq_backlog_size|event=OrderPlaced,transport=kafka'] ?? null,
        );
        $this->assertGreaterThanOrEqual(
            0.0,
            $metrics->gauges['outbox_oldest_pending_age_seconds|transport=kafka'] ?? -1.0,
        );
        $this->assertGreaterThanOrEqual(
            0.0,
            $metrics->gauges['dlq_oldest_failed_age_seconds|transport=kafka'] ?? -1.0,
        );
    }

    public function test_zeros_previous_labels_when_backlog_disappears_in_same_worker(): void
    {
        $connection = $this->createMock(Connection::class);
        $metrics = new RecordingMetrics();

        $connection->expects($this->exactly(8))
            ->method('fetchAllAssociative')
            ->willReturnOnConsecutiveCalls(
                [['transport_name' => 'kafka', 'status' => 'pending', 'backlog' => 1]],
                [['transport_name' => 'kafka', 'oldest_created_at' => '2026-05-12 00:00:00']],
                [['transport_name' => 'kafka', 'event_class' => 'App\\Event\\PaymentFailed', 'backlog' => 1]],
                [['transport_name' => 'kafka', 'oldest_failed_at' => '2026-05-12 00:00:00']],
                [],
                [],
                [],
                [],
            );

        $collector = new OperationalMessagingMetricsCollector($connection, new FrameworkTelemetry($metrics));
        $collector->collect();
        $collector->collect();

        $this->assertSame(
            0.0,
            $metrics->gauges['outbox_backlog_size|status=pending,transport=kafka'] ?? null,
        );
        $this->assertSame(
            0.0,
            $metrics->gauges['outbox_oldest_pending_age_seconds|transport=kafka'] ?? null,
        );
        $this->assertSame(
            0.0,
            $metrics->gauges['dlq_backlog_size|event=PaymentFailed,transport=kafka'] ?? null,
        );
        $this->assertSame(
            0.0,
            $metrics->gauges['dlq_oldest_failed_age_seconds|transport=kafka'] ?? null,
        );
    }

    public function test_rejects_unsafe_table_identifiers(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new OperationalMessagingMetricsCollector(
            $this->createMock(Connection::class),
            new FrameworkTelemetry(new RecordingMetrics()),
            'vortos_outbox; DROP TABLE users',
        );
    }
}

final class RecordingMetrics implements MetricsInterface
{
    /** @var array<string, float> */
    public array $gauges = [];

    public function counter(string $name, array $labels = []): CounterInterface
    {
        return new RecordingCounter();
    }

    public function gauge(string $name, array $labels = []): GaugeInterface
    {
        return new RecordingGauge($this, $name, $labels);
    }

    public function histogram(string $name, array $labels = []): HistogramInterface
    {
        return new RecordingHistogram();
    }

    /**
     * @param array<string, string> $labels
     */
    public function recordGauge(string $name, array $labels, float $value): void
    {
        ksort($labels);
        $parts = [];
        foreach ($labels as $key => $labelValue) {
            $parts[] = $key . '=' . $labelValue;
        }

        $this->gauges[$name . '|' . implode(',', $parts)] = $value;
    }
}

final class RecordingGauge implements GaugeInterface
{
    /**
     * @param array<string, string> $labels
     */
    public function __construct(
        private readonly RecordingMetrics $metrics,
        private readonly string $name,
        private readonly array $labels,
    ) {
    }

    public function set(float $value): void
    {
        $this->metrics->recordGauge($this->name, $this->labels, $value);
    }

    public function increment(float $by = 1.0): void
    {
        $this->set($by);
    }

    public function decrement(float $by = 1.0): void
    {
        $this->set(-$by);
    }
}

final class RecordingCounter implements CounterInterface
{
    public function increment(float $by = 1.0): void
    {
    }
}

final class RecordingHistogram implements HistogramInterface
{
    public function observe(float $value): void
    {
    }
}
