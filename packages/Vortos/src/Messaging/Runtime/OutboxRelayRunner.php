<?php

declare(strict_types=1);

namespace Vortos\Messaging\Runtime;

use Psr\Log\LoggerInterface;
use Vortos\Messaging\Outbox\OutboxRelayWorker;

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

    public function __construct(
        private readonly OutboxRelayWorker $worker,
        private readonly LoggerInterface $logger,
    ) {}

    public function run(int $batchSize, int $sleepMs): void
    {
        $this->running = true;
        $backoff = 1;

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
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }
}