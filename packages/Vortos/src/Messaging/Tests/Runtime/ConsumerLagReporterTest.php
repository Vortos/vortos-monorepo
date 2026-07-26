<?php

declare(strict_types=1);

namespace Vortos\Messaging\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Vortos\Messaging\Runtime\ConsumerLagReporter;
use Vortos\Metrics\Contract\CounterInterface;
use Vortos\Metrics\Contract\GaugeInterface;
use Vortos\Metrics\Contract\HistogramInterface;
use Vortos\Metrics\Contract\MetricsInterface;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;

/**
 * The guard rails matter more than the arithmetic: each one is a way to publish a number that
 * either invents a backlog or hides a real one.
 */
final class ConsumerLagReporterTest extends TestCase
{
    public function test_lag_is_high_watermark_minus_committed_offset(): void
    {
        $metrics = new RecordingMetrics();

        $this->reporter($metrics)->reportPartitionLag(
            'registration-submitted',
            'reg-consumers',
            'registration.submitted',
            0,
            committedOffset: 940,
            highWatermark: 1000,
        );

        $sample = $metrics->gauges['messaging_consumer_lag'][0];

        self::assertSame(60.0, $sample['value']);
        self::assertSame('registration.submitted', $sample['labels']['topic']);
        self::assertSame('0', $sample['labels']['partition']);
        self::assertSame('reg-consumers', $sample['labels']['consumer_group']);
        self::assertSame('registration-submitted', $sample['labels']['consumer']);
    }

    public function test_a_group_that_never_committed_reports_nothing_rather_than_a_phantom_backlog(): void
    {
        $metrics = new RecordingMetrics();

        // -1001 is rdkafka's RD_KAFKA_OFFSET_INVALID. Watermark-minus-sentinel would publish a lag
        // of 2001 on every brand-new consumer group and page someone on each fresh deploy.
        $this->reporter($metrics)->reportPartitionLag(
            'registration-submitted',
            'reg-consumers',
            'registration.submitted',
            0,
            committedOffset: -1001,
            highWatermark: 1000,
        );

        self::assertArrayNotHasKey('messaging_consumer_lag', $metrics->gauges);
    }

    public function test_an_unanswered_watermark_query_reports_nothing_rather_than_zero(): void
    {
        $metrics = new RecordingMetrics();

        $this->reporter($metrics)->reportPartitionLag(
            'registration-submitted',
            'reg-consumers',
            'registration.submitted',
            0,
            committedOffset: 940,
            highWatermark: null,
        );

        self::assertArrayNotHasKey(
            'messaging_consumer_lag',
            $metrics->gauges,
            'Publishing 0 for an unanswered query would silence a real backlog while the broker is unreachable.',
        );
    }

    public function test_lag_is_clamped_at_zero_when_watermark_and_offset_race(): void
    {
        $metrics = new RecordingMetrics();

        $this->reporter($metrics)->reportPartitionLag(
            'registration-submitted',
            'reg-consumers',
            'registration.submitted',
            0,
            committedOffset: 1005,
            highWatermark: 1000,
        );

        self::assertSame(0.0, $metrics->gauges['messaging_consumer_lag'][0]['value']);
    }

    public function test_starved_consumer_is_visible_as_zero_assigned_partitions(): void
    {
        $metrics = new RecordingMetrics();

        $this->reporter($metrics)->reportAssignedPartitions('registration-submitted', 'reg-consumers', 0);

        self::assertSame(0.0, $metrics->gauges['messaging_consumer_assigned_partitions'][0]['value']);
    }

    public function test_poll_cycles_counter_carries_the_batch_of_cycles_since_the_last_sample(): void
    {
        $metrics = new RecordingMetrics();

        $this->reporter($metrics)->reportPollCycles('registration-submitted', 30);

        self::assertSame(30.0, $metrics->counters['messaging_consumer_poll_cycles_total'][0]['value']);
        self::assertSame('registration-submitted', $metrics->counters['messaging_consumer_poll_cycles_total'][0]['labels']['consumer']);
    }

    public function test_without_the_metrics_package_every_call_is_a_no_op(): void
    {
        $reporter = new ConsumerLagReporter(null);

        $reporter->reportPollCycles('c', 1);
        $reporter->reportAssignedPartitions('c', 'g', 1);
        $reporter->reportPartitionLag('c', 'g', 't', 0, 1, 2);

        $this->expectNotToPerformAssertions();
    }

    private function reporter(RecordingMetrics $metrics): ConsumerLagReporter
    {
        return new ConsumerLagReporter(new FrameworkTelemetry($metrics));
    }
}

final class RecordingMetrics implements MetricsInterface
{
    /** @var array<string, list<array{labels: array<string, string>, value: float}>> */
    public array $gauges = [];

    /** @var array<string, list<array{labels: array<string, string>, value: float}>> */
    public array $counters = [];

    public function counter(string $name, array $labels = []): CounterInterface
    {
        return new class ($this, $name, $labels) implements CounterInterface {
            public function __construct(
                private RecordingMetrics $sink,
                private string $name,
                private array $labels,
            ) {}

            public function increment(float $by = 1.0): void
            {
                $this->sink->counters[$this->name][] = ['labels' => $this->labels, 'value' => $by];
            }
        };
    }

    public function gauge(string $name, array $labels = []): GaugeInterface
    {
        return new class ($this, $name, $labels) implements GaugeInterface {
            public function __construct(
                private RecordingMetrics $sink,
                private string $name,
                private array $labels,
            ) {}

            public function set(float $value): void
            {
                $this->sink->gauges[$this->name][] = ['labels' => $this->labels, 'value' => $value];
            }

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
