<?php

declare(strict_types=1);

namespace Vortos\Paddle\Inbox;

interface PaddleInboxWriterInterface
{
    /**
     * Persists a verified webhook payload as a pending inbox row.
     *
     * Returns false when the event_id has already been accepted (duplicate
     * delivery) — the UNIQUE constraint on event_id IS the idempotency check.
     *
     * @param array<string, mixed> $payload Decoded, signature-verified body
     */
    public function accept(string $eventId, string $eventType, array $payload, ?\DateTimeImmutable $occurredAt): bool;
}
