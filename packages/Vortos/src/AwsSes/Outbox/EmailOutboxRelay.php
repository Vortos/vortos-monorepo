<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Outbox;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use Vortos\AwsSes\Contract\MailerInterface;

/**
 * Picks up pending outbox rows and delivers them via the mailer.
 *
 * Each call to relay() processes up to $batchSize pending rows.
 * Returns the number of emails successfully sent.
 *
 * The relay:
 *   1. Selects pending rows where next_attempt_at IS NULL OR next_attempt_at <= now()
 *   2. Sets idempotency_key on each email so DeduplicationMiddleware prevents double-sends
 *   3. Sends via MailerInterface (runs the full middleware stack)
 *   4. Marks rows as 'sent' on success or increments attempt_count on failure
 *   5. Dead-letters rows that exceed max_delivery_attempts
 *
 * Use FOR UPDATE SKIP LOCKED (supported by PostgreSQL and MySQL 8+) for safe
 * parallel execution — multiple workers process different rows simultaneously.
 */
final class EmailOutboxRelay
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly string $tableName,
        private readonly int $batchSize,
        private readonly int $maxDeliveryAttempts,
        private readonly int $backoffBaseSeconds,
        private readonly int $backoffCapSeconds,
    ) {}

    /**
     * Process one batch of pending outbox rows.
     *
     * @return int Number of emails successfully sent.
     */
    public function relay(): int
    {
        $rows = $this->fetchPendingBatch();

        if ($rows === []) {
            return 0;
        }

        $sent = 0;

        foreach ($rows as $row) {
            if ($this->processRow($row)) {
                ++$sent;
            }
        }

        return $sent;
    }

    private function fetchPendingBatch(): array
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return $this->connection->executeQuery(
            "SELECT id, domain_event_id, payload, attempt_count
             FROM {$this->tableName}
             WHERE status = 'pending'
               AND (next_attempt_at IS NULL OR next_attempt_at <= :now)
             ORDER BY created_at ASC
             LIMIT :limit
             FOR UPDATE SKIP LOCKED",
            ['now' => $now, 'limit' => $this->batchSize],
            ['limit' => ParameterType::INTEGER],
        )->fetchAllAssociative();
    }

    private function processRow(array $row): bool
    {
        $outboxId = (string) $row['id'];

        try {
            $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
            $email   = EmailSerializer::fromArray($payload);

            // Inject outbox_id as idempotency key so DeduplicationMiddleware prevents double sends
            $email = $email->withMeta('idempotency_key', $outboxId);
            if ($row['domain_event_id'] !== null) {
                $email = $email->withMeta('domain_event_id', (string) $row['domain_event_id']);
            }

            $result = $this->mailer->send($email);

            $this->markSent($outboxId, $result->messageId());

            return true;
        } catch (\Throwable $e) {
            $attempt = (int) $row['attempt_count'] + 1;
            $this->markFailed($outboxId, $e, $attempt);

            $this->logger->warning('ses.outbox: delivery failed', [
                'outbox_id' => $outboxId,
                'attempt'   => $attempt,
                'error'     => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function markSent(string $id, string $messageId): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->connection->executeStatement(
            "UPDATE {$this->tableName}
             SET status = 'sent', message_id = :messageId, sent_at = :now, last_error = NULL
             WHERE id = :id",
            ['messageId' => $messageId, 'now' => $now, 'id' => $id],
        );
    }

    private function markFailed(string $id, \Throwable $error, int $attempt): void
    {
        if ($attempt >= $this->maxDeliveryAttempts) {
            $this->connection->executeStatement(
                "UPDATE {$this->tableName}
                 SET status = 'dead', attempt_count = :attempt, last_error = :error
                 WHERE id = :id",
                ['attempt' => $attempt, 'error' => $error->getMessage(), 'id' => $id],
            );
            return;
        }

        $backoffSec     = min($this->backoffCapSeconds, $this->backoffBaseSeconds * (2 ** ($attempt - 1)));
        $nextAttemptAt  = (new DateTimeImmutable())->modify("+{$backoffSec} seconds")->format('Y-m-d H:i:s');

        $this->connection->executeStatement(
            "UPDATE {$this->tableName}
             SET status = 'pending', attempt_count = :attempt,
                 next_attempt_at = :nextAttemptAt, last_error = :error
             WHERE id = :id",
            [
                'attempt'       => $attempt,
                'nextAttemptAt' => $nextAttemptAt,
                'error'         => $error->getMessage(),
                'id'            => $id,
            ],
        );
    }
}
