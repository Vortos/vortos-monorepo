<?php

declare(strict_types=1);

namespace Vortos\Paddle\AuditLog;

use Doctrine\DBAL\Connection;

final class PaddleAuditLogWriter implements PaddleAuditLogWriterInterface
{
    private const TABLE = 'paddle_audit_log';

    public function __construct(private readonly Connection $connection) {}

    public function record(PaddleAuditEntry $entry): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $this->connection->insert(self::TABLE, [
            'event_type'      => $entry->eventType,
            'paddle_event_id' => $entry->paddleEventId,
            'entity_type'     => $entry->entityType,
            'entity_id'       => $entry->entityId,
            'actor'           => $entry->actor,
            'occurred_at'     => $entry->occurredAt->format('Y-m-d H:i:s'),
            'recorded_at'     => $now->format('Y-m-d H:i:s'),
        ]);
    }
}
