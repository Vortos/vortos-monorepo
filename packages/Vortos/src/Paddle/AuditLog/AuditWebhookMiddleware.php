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
        $this->inner->dispatch($event);

        $this->writer->record(new PaddleAuditEntry(
            eventType:      $event->eventType,
            paddleEventId:  $event->eventId,
            entityType:     $this->resolveEntityType($event->eventType),
            entityId:       $this->resolveEntityId($event),
            actor:          'webhook',
            occurredAt:     $event->occurredAt,
        ));
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
