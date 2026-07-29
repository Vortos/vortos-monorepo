<?php

declare(strict_types=1);

namespace Vortos\Messaging\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Vortos\Messaging\Contract\OutboxPollerInterface;
use Vortos\Messaging\Contract\ProducerInterface;
use Vortos\Messaging\Outbox\OutboxRelayWorker;
use Vortos\Messaging\Registry\TransportRegistry;
use Vortos\Messaging\Serializer\SerializerLocator;
use Vortos\Messaging\Runtime\OutboxRelayRunner;
use Vortos\Metrics\Contract\FlushableMetricsInterface;

/**
 * The relay is a daemon, so ConsoleEvents::TERMINATE never fires and anything recorded inside the
 * loop is never exported. That is not hypothetical: vortos_aws_ses_send_total is recorded by the
 * SES middleware, which runs in this process, and had never once reached the metrics store on a
 * production installation that was demonstrably sending mail.
 */
final class OutboxRelayRunnerTest extends TestCase
{
    /** @var int number of relay() passes the fake poller has served */
    private int $passes = 0;

    /**
     * OutboxRelayWorker is final and cannot be doubled, so this builds a real one over a poller
     * reporting an empty outbox — relay() then returns 0 without touching the rest of the graph.
     * $onPass runs once per loop iteration, which is how each test stops the daemon.
     */
    private function runnerOverEmptyOutbox(
        ?FlushableMetricsInterface $flusher,
        callable $onPass,
        int $intervalMs = 5000,
    ): OutboxRelayRunner {
        $poller = $this->createMock(OutboxPollerInterface::class);
        $poller->method('fetchPending')->willReturnCallback(function () use ($onPass): array {
            $this->passes++;
            $onPass();

            return [];
        });

        $worker = new OutboxRelayWorker(
            $poller,
            $this->createMock(ProducerInterface::class),
            new SerializerLocator([]),
            new TransportRegistry([]),
            new NullLogger(),
        );

        return new OutboxRelayRunner($worker, new NullLogger(), $flusher, $intervalMs);
    }

    public function test_flushes_telemetry_on_a_clean_exit(): void
    {
        $flusher = $this->createMock(FlushableMetricsInterface::class);
        $flusher->expects(self::atLeastOnce())->method('flush');

        $runner = null;
        // Stop after the first pass so the loop terminates deterministically.
        $runner = $this->runnerOverEmptyOutbox($flusher, static function () use (&$runner): void {
            $runner->stop();
        });

        $runner->run(batchSize: 10, sleepMs: 0);
    }

    public function test_throttles_flushing_rather_than_exporting_every_iteration(): void
    {
        $flusher = $this->createMock(FlushableMetricsInterface::class);
        // Several iterations, one long interval: only the forced drain on exit should flush.
        $flusher->expects(self::once())->method('flush');

        $runner = null;
        $runner = $this->runnerOverEmptyOutbox($flusher, function () use (&$runner): void {
            if ($this->passes >= 5) {
                $runner->stop();
            }
        }, intervalMs: 600_000);

        $runner->run(batchSize: 10, sleepMs: 0);

        self::assertSame(5, $this->passes);
    }

    public function test_a_flush_failure_never_breaks_the_relay(): void
    {
        $flusher = $this->createMock(FlushableMetricsInterface::class);
        $flusher->method('flush')->willThrowException(new \RuntimeException('collector down'));

        $runner = null;
        $runner = $this->runnerOverEmptyOutbox($flusher, static function () use (&$runner): void {
            $runner->stop();
        });

        $runner->run(batchSize: 10, sleepMs: 0);

        // Dropping a metrics sample is acceptable; dropping a customer's message is not.
        self::assertSame(1, $this->passes);
    }

    public function test_works_without_a_flusher_at_all(): void
    {
        $runner = null;
        $runner = $this->runnerOverEmptyOutbox(null, static function () use (&$runner): void {
            $runner->stop();
        });

        $runner->run(batchSize: 10, sleepMs: 0);

        self::assertSame(1, $this->passes);
    }
}
