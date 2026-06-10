<?php

declare(strict_types=1);

namespace Vortos\Paddle\Inbox;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use Vortos\Paddle\Webhook\PaddleWebhookDispatcher;
use Vortos\Paddle\Webhook\WebhookEventFactory;

/**
 * Drains the Paddle webhook inbox: claims due pending rows, dispatches each
 * to its matching handlers, and records the outcome.
 *
 *   success            → processed
 *   handler threw      → stays pending; attempts++, next_attempt_at pushed
 *                        back with exponential backoff (base * 2^attempt, capped)
 *   attempts exhausted → dead (visible to doctor; revive via paddle:inbox:replay)
 *
 * Handlers that already completed for a row are recorded in
 * completed_handlers and skipped on retry — a multi-handler row that fails
 * halfway never re-runs the handlers that succeeded.
 *
 * Claiming uses FOR UPDATE SKIP LOCKED inside a short transaction (same
 * pattern as PaddleOutboxRelay) so multiple workers can run concurrently.
 */
final class PaddleInboxWorker implements PaddleInboxWorkerInterface
{
    public function __construct(
        private readonly Connection              $connection,
        private readonly PaddleWebhookDispatcher $dispatcher,
        private readonly WebhookEventFactory     $eventFactory,
        private readonly LoggerInterface         $logger,
        private readonly string                  $table,
        private readonly int                     $batchSize          = 50,
        private readonly int                     $maxAttempts        = 5,
        private readonly int                     $backoffBaseSeconds = 60,
        private readonly int                     $backoffCapSeconds  = 3600,
    ) {}

    public function process(): int
    {
        $rows      = $this->fetchDueBatch();
        $processed = 0;

        foreach ($rows as $row) {
            if ($this->processRow($row)) {
                ++$processed;
            }
        }

        return $processed;
    }

    private function fetchDueBatch(): array
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        return $this->connection->executeQuery(
            'SELECT id, event_id, event_type, payload, attempts, completed_handlers
             FROM ' . $this->table . '
             WHERE status = :status
               AND next_attempt_at <= :now
             ORDER BY received_at ASC
             LIMIT :limit
             FOR UPDATE SKIP LOCKED',
            ['status' => InboxStatus::Pending->value, 'now' => $now, 'limit' => $this->batchSize],
            ['limit' => ParameterType::INTEGER],
        )->fetchAllAssociative();
    }

    private function processRow(array $row): bool
    {
        $id        = (int) $row['id'];
        $attempt   = (int) $row['attempts'] + 1;
        $completed = json_decode((string) ($row['completed_handlers'] ?? '[]'), true) ?: [];

        try {
            $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
            $event   = $this->eventFactory->fromVerifiedPayload($payload);
        } catch (\Throwable $e) {
            // Unparseable payload can never succeed — straight to dead.
            $this->markDead($id, $attempt, $completed, 'Payload unparseable: ' . $e->getMessage());
            $this->logger->error('paddle.inbox: payload unparseable, row dead-lettered', [
                'id'       => $id,
                'event_id' => $row['event_id'],
            ]);
            return false;
        }

        foreach ($this->dispatcher->handlersFor($event->eventType) as $handler) {
            if (in_array($handler::class, $completed, true)) {
                continue;
            }

            try {
                $handler->handle($event);
                $completed[] = $handler::class;
            } catch (\Throwable $e) {
                if ($attempt >= $this->maxAttempts) {
                    $this->markDead($id, $attempt, $completed, $e->getMessage());
                    $this->logger->error('paddle.inbox: attempts exhausted, row dead-lettered', [
                        'id'         => $id,
                        'event_id'   => $row['event_id'],
                        'event_type' => $event->eventType,
                        'handler'    => $handler::class,
                        'attempts'   => $attempt,
                        'error'      => $e->getMessage(),
                    ]);
                } else {
                    $this->scheduleRetry($id, $attempt, $completed, $e->getMessage());
                    $this->logger->warning('paddle.inbox: handler failed, retry scheduled', [
                        'id'         => $id,
                        'event_id'   => $row['event_id'],
                        'event_type' => $event->eventType,
                        'handler'    => $handler::class,
                        'attempt'    => $attempt,
                        'error'      => $e->getMessage(),
                    ]);
                }

                return false;
            }
        }

        $this->markProcessed($id, $completed);
        return true;
    }

    private function markProcessed(int $id, array $completed): void
    {
        $this->connection->executeStatement(
            'UPDATE ' . $this->table . '
             SET status = :status, completed_handlers = :completed, processed_at = :now, last_error = NULL
             WHERE id = :id',
            [
                'status'    => InboxStatus::Processed->value,
                'completed' => json_encode($completed),
                'now'       => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'id'        => $id,
            ],
            ['id' => ParameterType::INTEGER],
        );
    }

    private function scheduleRetry(int $id, int $attempt, array $completed, string $error): void
    {
        $delay = min($this->backoffBaseSeconds * (2 ** ($attempt - 1)), $this->backoffCapSeconds);
        $next  = (new \DateTimeImmutable())->modify(sprintf('+%d seconds', $delay));

        $this->connection->executeStatement(
            'UPDATE ' . $this->table . '
             SET attempts = :attempts, completed_handlers = :completed,
                 last_error = :error, next_attempt_at = :next
             WHERE id = :id',
            [
                'attempts'  => $attempt,
                'completed' => json_encode($completed),
                'error'     => mb_substr($error, 0, 2000),
                'next'      => $next->format('Y-m-d H:i:s'),
                'id'        => $id,
            ],
            ['attempts' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
        );
    }

    private function markDead(int $id, int $attempt, array $completed, string $error): void
    {
        $this->connection->executeStatement(
            'UPDATE ' . $this->table . '
             SET status = :status, attempts = :attempts, completed_handlers = :completed,
                 last_error = :error
             WHERE id = :id',
            [
                'status'    => InboxStatus::Dead->value,
                'attempts'  => $attempt,
                'completed' => json_encode($completed),
                'error'     => mb_substr($error, 0, 2000),
                'id'        => $id,
            ],
            ['attempts' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
        );
    }
}
