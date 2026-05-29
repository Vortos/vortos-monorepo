<?php

declare(strict_types=1);

namespace Vortos\Paddle\Webhook\Event;

abstract class PaddleWebhookEvent
{
    public function __construct(
        public readonly string             $eventId,
        public readonly string             $notificationId,
        public readonly string             $eventType,
        public readonly \DateTimeImmutable $occurredAt,
        public readonly array              $data,
    ) {}
}
