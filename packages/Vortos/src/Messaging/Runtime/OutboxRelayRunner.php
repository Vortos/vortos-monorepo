<?php

declare(strict_types=1);

namespace Vortos\Messaging\Runtime;

use Psr\Log\LoggerInterface;
use Vortos\Messaging\Outbox\OutboxRelayWorker;
use Vortos\Metrics\Contract\FlushableMetricsInterface;

/**
 * Runs the outbox relay loop continuously.
 *
 * When the relay worker returns 0 (nothing to relay), sleeps for sleepMs
 * milliseconds before polling again to avoid hammering the database.
 * When a full batch is returned, loops immediately — more messages may be waiting.
 * Exits cleanly when stop() is called, e.g. by a SIGTERM signal handler.
 *
 * On transient errors (e.g. table not yet migrated at startup), retries with
 * exponential backoff: 1s → 2s → 4s → … capped at 300s (5 min).
 */
final class OutboxRelayRunner
{
    private bool $running = false;
    private int $lastTelemetryFlushNs = 0;

    public function __construct(
        private readonly OutboxRelayWorker $worker,
        private readonly LoggerInterface $logger,
        // Push telemetry (OTLP/StatsD) is only delivered when flush() runs. HTTP requests flush on
        // kernel.terminate and consumers flush in their own loop, but this relay is a single
        // long-lived command whose ConsoleEvents::TERMINATE fires only at process exit — which for
        // a daemon never happens. Everything recorded inside the relay therefore accumulated in
        // memory and was never exported, including every vortos_aws_ses_send_total: mail was
        // demonstrably going out while the counter measuring it had never once reached the metrics
        // store. Null-safe and a no-op for NoOp adapters.
        private readonly ?FlushableMetricsInterface $metricsFlusher = null,
        private int $telemetryFlushIntervalMs = 5000,
    ) {
        $this->telemetryFlushIntervalMs = max(250, $this->telemetryFlushIntervalMs);
    }

    public function run(int $batchSize, int $sleepMs): void
    {
        $this->running = true;
        $backoff = 1;
        // Start the clock now so the first flush honours the full interval rather than firing
        // immediately on the first pass.
        $this->lastTelemetryFlushNs = hrtime(true);

        try {
            while ($this->running) {
                try {
                    $relayed = $this->worker->relay($batchSize);
                    $backoff  = 1;

                    if ($relayed === 0) {
                        usleep($sleepMs * 1000);
                    }
                } catch (\Throwable $e) {
                    $this->logger->error('Outbox relay poll failed, retrying in {delay}s', [
                        'delay' => $backoff,
                        'error' => $e->getMessage(),
                    ]);
                    sleep($backoff);
                    $backoff = min($backoff * 2, 300);
                }

                // Outside the inner catch: a poll that failed still produced telemetry worth
                // exporting, and the failure path is exactly when someone is watching.
                $this->flushTelemetry();
            }
        } finally {
            // Drain on the way out so a clean SIGTERM does not discard the final window.
            $this->flushTelemetry(force: true);
        }
    }

    /**
     * Drains push-based telemetry, at most once per configured interval.
     *
     * Throttled because the relay loops continuously and an unconditional flush would issue an
     * export per iteration. A flush failure must never break the relay: dropping a metrics sample
     * is acceptable, dropping a customer's message is not.
     */
    private function flushTelemetry(bool $force = false): void
    {
        if ($this->metricsFlusher === null) {
            return;
        }

        $now = hrtime(true);

        if (!$force && ($now - $this->lastTelemetryFlushNs) < $this->telemetryFlushIntervalMs * 1_000_000) {
            return;
        }

        $this->lastTelemetryFlushNs = $now;

        try {
            $this->metricsFlusher->flush();
        } catch (\Throwable $e) {
            $this->logger->warning('Telemetry flush failed during outbox relay loop', [
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }
}
