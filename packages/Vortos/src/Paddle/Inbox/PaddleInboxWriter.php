<?php

declare(strict_types=1);

namespace Vortos\Paddle\Inbox;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Persists verified Paddle webhooks into the inbox table.
 *
 * Called by PaddleWebhookController AFTER signature/IP verification and
 * BEFORE any handler runs. Once the insert commits, the webhook is durable —
 * Paddle gets its 200 and processing becomes the inbox worker's problem,
 * with retries and dead-lettering instead of in-request best effort.
 */
final class PaddleInboxWriter implements PaddleInboxWriterInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string     $table,
    ) {}

    public function accept(string $eventId, string $eventType, array $payload, ?\DateTimeImmutable $occurredAt): bool
    {
        $now = new \DateTimeImmutable();

        try {
            $this->connection->executeStatement(
                'INSERT INTO ' . $this->table . '
                 (event_id, event_type, payload, status, attempts, occurred_at, received_at, next_attempt_at)
                 VALUES (:event_id, :event_type, :payload, :status, 0, :occurred_at, :received_at, :next_attempt_at)',
                [
                    'event_id'        => $eventId,
                    'event_type'      => $eventType,
                    'payload'         => json_encode($payload, JSON_THROW_ON_ERROR),
                    'status'          => InboxStatus::Pending->value,
                    'occurred_at'     => $occurredAt?->format('Y-m-d H:i:s'),
                    'received_at'     => $now->format('Y-m-d H:i:s'),
                    'next_attempt_at' => $now->format('Y-m-d H:i:s'),
                ],
            );

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }
}
