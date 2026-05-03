<?php

declare(strict_types=1);

namespace Vortos\Messaging\Outbox;

use Doctrine\DBAL\Connection;
use Vortos\Messaging\Contract\OutboxPollerInterface;

/**
 * Polls the outbox table for messages pending relay to the broker.
 * 
 * Uses FOR UPDATE SKIP LOCKED so multiple relay worker processes can run
 * in parallel without processing the same message twice. Each worker
 * locks a batch of rows, processes them, and releases the lock on commit.
 * 
 * markFailed() uses exponential backoff — delay doubles on each attempt,
 * capped at 1 hour. After maxAttempts the message is permanently failed
 * and requires manual intervention or a replay command.
 */
final class OutboxPoller implements OutboxPollerInterface
{
    public function __construct(
        private Connection $connection,
        private string $tableName = 'vortos_outbox',
        private int $maxAttempts = 5
    ) {}

    public function fetchPending(int $limit = 100): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT * FROM {$this->tableName}
     WHERE status = 'pending'
     AND (next_attempt_at IS NULL OR next_attempt_at <= :now)
     ORDER BY created_at ASC
     LIMIT :limit
     FOR UPDATE SKIP LOCKED",
            [
                'now'   => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'limit' => $limit,
            ],
            [
                'limit' => \Doctrine\DBAL\ParameterType::INTEGER,
            ]
        );

        $messages = [];
        foreach ($rows as $row) {
            $messages[] = OutboxMessage::fromDatabaseRow($row);
        }

        return $messages;
    }

    public function markPublished(string $outboxId): void
    {
        $this->connection->update($this->tableName, [
            'status'       => 'published',
            'published_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], ['id' => $outboxId]);
    }

    public function markFailed(string $outboxId, string $reason): void
    {
        $currentAttemptCount = (int) $this->connection->fetchOne(
            "SELECT attempt_count FROM {$this->tableName} WHERE id = :id",
            [
                'id' => $outboxId
            ]
        );

        $newAttemptCount = $currentAttemptCount + 1;

        $delaySeconds = min(30 * (2 ** $newAttemptCount), 3600);

        $nextAttemptAt = (new \DateTimeImmutable())->modify("+{$delaySeconds} seconds");

        if($newAttemptCount >= $this->maxAttempts){
            $status = 'failed';
            $nextAttemptAt = null;
        }else{
            $status = 'pending';
            }

        $this->connection->update($this->tableName, [
            'status'       => $status,
            'attempt_count'       => $newAttemptCount,
            'failure_reason'       => $reason,
            'next_attempt_at' => $nextAttemptAt?->format('Y-m-d H:i:s')
        ], ['id' => $outboxId]);
    }

    public function fetchFailed(int $limit = 50): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT * FROM {$this->tableName}
        WHERE status = 'failed'
        ORDER BY created_at ASC
        LIMIT :limit",
            ['limit' => $limit],
            ['limit' => \Doctrine\DBAL\ParameterType::INTEGER]
        );

        $messages = [];
        foreach ($rows as $row) {
            $messages[] = OutboxMessage::fromDatabaseRow($row);
        }
        return $messages;
    }
}
