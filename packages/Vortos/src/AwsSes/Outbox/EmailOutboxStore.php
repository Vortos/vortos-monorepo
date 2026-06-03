<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Outbox;

use Doctrine\DBAL\Connection;
use Vortos\AwsSes\Contract\EmailOutboxStoreInterface;

final class EmailOutboxStore implements EmailOutboxStoreInterface
{
    private const COLUMNS = 'id, status, message_id, attempt_count, created_at, sent_at, last_error';

    public function __construct(
        private readonly Connection $connection,
        private readonly string $tableName,
    ) {}

    public function findById(string $outboxId): ?OutboxEntry
    {
        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . " FROM {$this->tableName} WHERE id = ?",
            [$outboxId],
        );

        return $row !== false ? $this->hydrate($row) : null;
    }

    public function findByAwsMessageId(string $awsMessageId): ?OutboxEntry
    {
        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . " FROM {$this->tableName} WHERE message_id = ?",
            [$awsMessageId],
        );

        return $row !== false ? $this->hydrate($row) : null;
    }

    private function hydrate(array $row): OutboxEntry
    {
        return new OutboxEntry(
            outboxId:     (string) $row['id'],
            status:       OutboxStatus::from((string) $row['status']),
            awsMessageId: isset($row['message_id']) ? (string) $row['message_id'] : null,
            attemptCount: (int) $row['attempt_count'],
            createdAt:    new \DateTimeImmutable((string) $row['created_at']),
            sentAt:       isset($row['sent_at']) ? new \DateTimeImmutable((string) $row['sent_at']) : null,
            lastError:    isset($row['last_error']) ? (string) $row['last_error'] : null,
        );
    }
}
