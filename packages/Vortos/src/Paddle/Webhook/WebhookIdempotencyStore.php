<?php

declare(strict_types=1);

namespace Vortos\Paddle\Webhook;

use Doctrine\DBAL\Connection;

final class WebhookIdempotencyStore
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string     $tableName,
        private readonly int        $ttlSeconds,
    ) {}

    public function hasBeenProcessed(string $eventId): bool
    {
        $count = $this->connection->fetchOne(
            sprintf(
                'SELECT COUNT(*) FROM %s WHERE event_id = :event_id AND expires_at > :now',
                $this->tableName,
            ),
            ['event_id' => $eventId, 'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
        );

        return (int) $count > 0;
    }

    public function markProcessed(string $eventId, string $eventType): void
    {
        $now       = new \DateTimeImmutable();
        $expiresAt = $now->modify(sprintf('+%d seconds', $this->ttlSeconds));

        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %s (event_id, event_type, received_at, expires_at) VALUES (:event_id, :event_type, :received_at, :expires_at)',
                $this->tableName,
            ),
            [
                'event_id'    => $eventId,
                'event_type'  => $eventType,
                'received_at' => $now->format('Y-m-d H:i:s'),
                'expires_at'  => $expiresAt->format('Y-m-d H:i:s'),
            ],
        );
    }

    public function pruneExpired(): int
    {
        return (int) $this->connection->executeStatement(
            sprintf(
                'DELETE FROM %s WHERE expires_at <= :now',
                $this->tableName,
            ),
            ['now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
        );
    }
}
