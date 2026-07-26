<?php

declare(strict_types=1);

namespace Vortos\Metrics\Tests\Supervisor;

use PHPUnit\Framework\TestCase;
use Vortos\Metrics\Contract\CounterInterface;
use Vortos\Metrics\Contract\GaugeInterface;
use Vortos\Metrics\Contract\HistogramInterface;
use Vortos\Metrics\Contract\MetricsInterface;
use Vortos\Metrics\Supervisor\SupervisorMetricsReporter;
use Vortos\Metrics\Supervisor\SupervisorProcessStatus;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;

/**
 * Restart detection is the part with state, and therefore the part that can be wrong.
 */
final class SupervisorMetricsReporterTest extends TestCase
{
    public function test_reports_up_and_uptime_per_program(): void
    {
        $metrics = new RecordingMetrics();

        $this->reporter($metrics)->report([
            new SupervisorProcessStatus('consumer-a', 'RUNNING', 100, 623),
            new SupervisorProcessStatus('scheduler-daemon', 'FATAL', null, null),
        ]);

        self::assertSame(1.0, $metrics->gauges['supervisor_program_up'][0]['value']);
        self::assertSame('consumer-a', $metrics->gauges['supervisor_program_up'][0]['labels']['program']);
        self::assertSame(0.0, $metrics->gauges['supervisor_program_up'][1]['value']);
        self::assertSame(623.0, $metrics->gauges['supervisor_program_uptime_seconds'][0]['value']);
        self::assertCount(1, $metrics->gauges['supervisor_program_uptime_seconds'], 'A dead program has no uptime to report.');
    }

    public function test_the_first_observation_is_a_baseline_not_a_restart(): void
    {
        $metrics = new RecordingMetrics();

        $this->reporter($metrics)->report([new SupervisorProcessStatus('consumer-a', 'RUNNING', 100, 10)]);

        self::assertArrayNotHasKey(
            'supervisor_program_restarts_total',
            $metrics->counters,
            'Counting the first sample would report a restart for every program each time the collector itself starts.',
        );
    }

    public function test_a_changed_pid_counts_as_one_restart(): void
    {
        $metrics = new RecordingMetrics();
        $reporter = $this->reporter($metrics);

        $reporter->report([new SupervisorProcessStatus('consumer-a', 'RUNNING', 100, 30)]);
        $reporter->report([new SupervisorProcessStatus('consumer-a', 'RUNNING', 101, 1)]);

        self::assertCount(1, $metrics->counters['supervisor_program_restarts_total']);
        self::assertSame('consumer-a', $metrics->counters['supervisor_program_restarts_total'][0]['labels']['program']);
    }

    public function test_a_steady_pid_never_counts_a_restart(): void
    {
        $metrics = new RecordingMetrics();
        $reporter = $this->reporter($metrics);

        $reporter->report([new SupervisorProcessStatus('consumer-a', 'RUNNING', 100, 30)]);
        $reporter->report([new SupervisorProcessStatus('consumer-a', 'RUNNING', 100, 45)]);
        $reporter->report([new SupervisorProcessStatus('consumer-a', 'RUNNING', 100, 60)]);

        self::assertArrayNotHasKey('supervisor_program_restarts_total', $metrics->counters);
    }

    public function test_recovery_from_a_down_state_counts_as_a_restart(): void
    {
        $metrics = new RecordingMetrics();
        $reporter = $this->reporter($metrics);

        $reporter->report([new SupervisorProcessStatus('consumer-a', 'RUNNING', 100, 30)]);
        $reporter->report([new SupervisorProcessStatus('consumer-a', 'FATAL', null, null)]);
        $reporter->report([new SupervisorProcessStatus('consumer-a', 'RUNNING', 105, 1)]);

        self::assertCount(1, $metrics->counters['supervisor_program_restarts_total']);
    }

    public function test_a_program_that_stays_down_is_not_counted_repeatedly(): void
    {
        $metrics = new RecordingMetrics();
        $reporter = $this->reporter($metrics);

        $reporter->report([new SupervisorProcessStatus('consumer-a', 'RUNNING', 100, 30)]);
        $reporter->report([new SupervisorProcessStatus('consumer-a', 'FATAL', null, null)]);
        $reporter->report([new SupervisorProcessStatus('consumer-a', 'FATAL', null, null)]);

        self::assertArrayNotHasKey(
            'supervisor_program_restarts_total',
            $metrics->counters,
            'A program stuck down is already visible as up=0; counting each sample would inflate the restart rate.',
        );
    }

    public function test_a_crash_loop_accumulates_restarts(): void
    {
        $metrics = new RecordingMetrics();
        $reporter = $this->reporter($metrics);

        foreach ([100, 101, 102, 103] as $pid) {
            $reporter->report([new SupervisorProcessStatus('consumer-a', 'RUNNING', $pid, 1)]);
        }

        self::assertCount(3, $metrics->counters['supervisor_program_restarts_total']);
    }

    public function test_memory_is_read_from_vm_rss_in_kilobytes(): void
    {
        $metrics = new RecordingMetrics();
        $proc = $this->fakeProc(4242, "Name:\tphp\nVmRSS:\t  65536 kB\nThreads:\t1\n");

        (new SupervisorMetricsReporter(new FrameworkTelemetry($metrics), $proc))
            ->report([new SupervisorProcessStatus('consumer-a', 'RUNNING', 4242, 10)]);

        // 65536 kB -> bytes. Deriving this from statm's page count would be 16x wrong on a
        // 64K-page aarch64 host.
        self::assertSame(67108864.0, $metrics->gauges['supervisor_program_memory_bytes'][0]['value']);
    }

    public function test_an_unreadable_proc_entry_reports_no_memory_rather_than_zero(): void
    {
        $metrics = new RecordingMetrics();

        (new SupervisorMetricsReporter(new FrameworkTelemetry($metrics), $this->fakeProc(1, null)))
            ->report([new SupervisorProcessStatus('consumer-a', 'RUNNING', 9999, 10)]);

        self::assertArrayNotHasKey('supervisor_program_memory_bytes', $metrics->gauges);
    }

    public function test_without_a_metrics_adapter_every_call_is_a_no_op(): void
    {
        (new SupervisorMetricsReporter(null))->report([
            new SupervisorProcessStatus('consumer-a', 'RUNNING', 100, 30),
        ]);

        $this->expectNotToPerformAssertions();
    }

    private function reporter(RecordingMetrics $metrics): SupervisorMetricsReporter
    {
        return new SupervisorMetricsReporter(new FrameworkTelemetry($metrics), $this->fakeProc(1, null));
    }

    private function fakeProc(int $pid, ?string $status): string
    {
        $root = sys_get_temp_dir() . '/vortos-proc-' . bin2hex(random_bytes(6));
        mkdir($root . '/' . $pid, 0777, true);

        if ($status !== null) {
            file_put_contents($root . '/' . $pid . '/status', $status);
        }

        return $root;
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
