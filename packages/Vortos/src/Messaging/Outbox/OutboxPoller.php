<?php

declare(strict_types=1);

namespace Vortos\Messaging\Outbox;

use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Vortos\Messaging\Contract\OutboxPollerInterface;

/**
 * Polls the outbox table for messages pending relay to the broker.
 *
 * Uses FOR UPDATE SKIP LOCKED so multiple relay worker processes can run
 * in parallel without processing the same message twice.
 *
 * markFailed() uses exponential backoff — delay doubles on each attempt,
 * capped at backoffCap. After maxAttempts the message is permanently failed
 * (status='failed') and requires vortos:outbox:replay to recover.
 *
 * All retry parameters are configurable via VortosMessagingConfig::outbox().
 */
final class OutboxPoller implements OutboxPollerInterface
{
    public function __construct(
        private Connection $connection,
        private string $tableName = 'vortos_outbox',
        private int $maxAttempts = 5,
        private int $backoffBase = 30,
        private int $backoffCap = 3600,
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
            ['limit' => ParameterType::INTEGER]
        );

        return array_map(fn(array $row) => OutboxMessage::fromDatabaseRow($row), $rows);
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
        $this->connection->executeStatement(
            "UPDATE {$this->tableName}
             SET
                 attempt_count   = attempt_count + 1,
                 failure_reason  = :reason,
                 status          = CASE WHEN attempt_count + 1 >= :max THEN 'failed' ELSE 'pending' END,
                 next_attempt_at = CASE
                     WHEN attempt_count + 1 >= :max THEN NULL
                     ELSE :base_time::timestamp + make_interval(secs => LEAST(:backoff_base * POWER(2, attempt_count + 1)::int, :backoff_cap))
                 END
             WHERE id = :id",
            [
                'reason'       => $reason,
                'max'          => $this->maxAttempts,
                'base_time'    => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'backoff_base' => $this->backoffBase,
                'backoff_cap'  => $this->backoffCap,
                'id'           => $outboxId,
            ]
        );
    }

    public function fetchFailed(
        int $limit = 50,
        ?string $transport = null,
        ?string $eventClass = null,
        ?string $id = null,
        bool $orderDesc = false,
        ?DateTimeInterface $createdFrom = null,
        ?DateTimeInterface $createdTo = null,
    ): array {
        $sql = "SELECT * FROM {$this->tableName} WHERE status = 'failed'";
        $params = ['limit' => $limit];
        $types  = ['limit' => ParameterType::INTEGER];

        if ($id !== null) {
            $sql .= ' AND id = :id';
            $params['id'] = $id;
        }

        if ($transport !== null) {
            $sql .= ' AND transport_name = :transport';
            $params['transport'] = $transport;
        }

        if ($eventClass !== null) {
            $sql .= ' AND event_class = :event_class';
            $params['event_class'] = $eventClass;
        }

        if ($createdFrom !== null) {
            $sql .= ' AND created_at >= :created_from';
            $params['created_from'] = $createdFrom->format('Y-m-d H:i:s');
        }

        if ($createdTo !== null) {
            $sql .= ' AND created_at <= :created_to';
            $params['created_to'] = $createdTo->format('Y-m-d H:i:s');
        }

        $sql .= $orderDesc
            ? ' ORDER BY created_at DESC LIMIT :limit FOR UPDATE SKIP LOCKED'
            : ' ORDER BY created_at ASC LIMIT :limit FOR UPDATE SKIP LOCKED';

        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);

        return array_map(fn(array $row) => OutboxMessage::fromDatabaseRow($row), $rows);
    }

    public function resetFailed(string $outboxId): void
    {
        $this->connection->update($this->tableName, [
            'status'          => 'pending',
            'attempt_count'   => 0,
            'failure_reason'  => null,
            'next_attempt_at' => null,
        ], ['id' => $outboxId]);
    }
}
