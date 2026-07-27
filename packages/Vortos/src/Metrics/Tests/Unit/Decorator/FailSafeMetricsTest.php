<?php

declare(strict_types=1);

namespace Vortos\Metrics\Tests\Unit\Decorator;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Vortos\Metrics\Decorator\FailSafeMetrics;
use Vortos\Metrics\Contract\CounterInterface;
use Vortos\Metrics\Contract\GaugeInterface;
use Vortos\Metrics\Contract\HistogramInterface;
use Vortos\Metrics\Contract\MetricsInterface;
use Vortos\Metrics\Exception\MetricNotDefinedException;
use Vortos\Metrics\Instrument\NoOpCounter;

/**
 * Recording a metric must never be able to change a business outcome.
 *
 * An undeclared metric threw out of whatever business code recorded it. In a Kafka consumer that
 * meant retry x4 into the dead-letter queue, so staff notifications were never delivered — no
 * in-app row, no email — because of an observability misconfiguration.
 */
final class FailSafeMetricsTest extends TestCase
{
    private function throwingInner(): MetricsInterface
    {
        return new class implements MetricsInterface {
            public function counter(string $name, array $labels = []): CounterInterface
            {
                throw new MetricNotDefinedException($name);
            }

            public function gauge(string $name, array $labels = []): GaugeInterface
            {
                throw new MetricNotDefinedException($name);
            }

            public function histogram(string $name, array $labels = []): HistogramInterface
            {
                throw new MetricNotDefinedException($name);
            }
        };
    }

    /**
     * A logger that keeps what it was told.
     *
     * Deliberately an object with a public array rather than a by-reference local: destructuring
     * `[$logger, $records]` copies the array, so a by-ref capture silently reports zero records and
     * the test passes for the wrong reason.
     */
    private function recordingLogger(): object
    {
        return new class extends AbstractLogger {
            /** @var array<int, array<string, mixed>> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }

    public function test_an_undeclared_metric_does_not_reach_the_caller_in_production(): void
    {
        $logger = $this->recordingLogger();

        $metrics = new FailSafeMetrics($this->throwingInner(), $logger, 'prod');

        // The whole point: this must not throw.
        $counter = $metrics->counter('notifications_dispatched_total', ['channel' => 'email']);

        self::assertInstanceOf(NoOpCounter::class, $counter);
        self::assertCount(1, $logger->records, 'the misconfiguration must still be reported once');
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame('notifications_dispatched_total', $logger->records[0]['context']['metric']);
    }

    public function test_gauge_and_histogram_degrade_too(): void
    {
        $metrics = new FailSafeMetrics($this->throwingInner(), null, 'prod');

        $metrics->gauge('undeclared_gauge');
        $metrics->histogram('undeclared_histogram');

        $this->addToAssertionCount(1); // reaching here without throwing is the assertion
    }

    public function test_it_logs_once_per_metric_not_once_per_call(): void
    {
        $logger = $this->recordingLogger();

        $metrics = new FailSafeMetrics($this->throwingInner(), $logger, 'prod');

        for ($i = 0; $i < 50; $i++) {
            $metrics->counter('hot_path_total');
        }

        self::assertCount(
            1,
            $logger->records,
            'A per-message counter would turn one misconfiguration into a log flood — a second '
            . 'incident caused by the mitigation for the first.',
        );
    }

    public function test_it_fails_fast_in_dev_and_test(): void
    {
        foreach (['dev', 'test'] as $env) {
            try {
                (new FailSafeMetrics($this->throwingInner(), null, $env))->counter('undeclared');
                self::fail(sprintf('Expected a throw in "%s": a missing declaration is a bug and must be loud where it is cheap to fix.', $env));
            } catch (MetricNotDefinedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_healthy_metric_passes_straight_through(): void
    {
        $inner = new class implements MetricsInterface {
            public function counter(string $name, array $labels = []): CounterInterface
            {
                return new NoOpCounter();
            }

            public function gauge(string $name, array $labels = []): GaugeInterface
            {
                throw new \LogicException('not used');
            }

            public function histogram(string $name, array $labels = []): HistogramInterface
            {
                throw new \LogicException('not used');
            }
        };

        $logger = $this->recordingLogger();

        (new FailSafeMetrics($inner, $logger, 'prod'))->counter('declared_total');

        self::assertSame([], $logger->records, 'a working metric must not log anything');
    }
}
