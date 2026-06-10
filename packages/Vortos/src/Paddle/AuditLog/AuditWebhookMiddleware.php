<?php

declare(strict_types=1);

namespace Vortos\Paddle\AuditLog;

use Vortos\Paddle\Webhook\PaddleWebhookDispatcher;
use Vortos\Paddle\Webhook\Event\PaddleWebhookEvent;

final class AuditWebhookMiddleware
{
    public function __construct(
        private readonly PaddleWebhookDispatcher       $inner,
        private readonly PaddleAuditLogWriterInterface $writer,
    ) {}

    public function dispatch(PaddleWebhookEvent $event): void
    {
        // Audit records the ATTEMPT, so it must be written even when a handler
        // throws — but the exception propagates: failure policy (retry/dead-letter)
        // belongs to the inbox worker, not the audit log.
        try {
            $this->inner->dispatch($event);
        } finally {
            $this->writer->record(new PaddleAuditEntry(
                eventType:      $event->eventType,
                paddleEventId:  $event->eventId,
                entityType:     $this->resolveEntityType($event->eventType),
                entityId:       $this->resolveEntityId($event),
                actor:          'webhook',
                occurredAt:     $event->occurredAt,
            ));
        }
    }

    private function resolveEntityType(string $eventType): string
    {
        // e.g. "subscription.created" → "subscription"
        $parts = explode('.', $eventType);
        return $parts[0] ?? $eventType;
    }

    private function resolveEntityId(PaddleWebhookEvent $event): string
    {
        return (string) ($event->data['id'] ?? $event->eventId);
    }
}
