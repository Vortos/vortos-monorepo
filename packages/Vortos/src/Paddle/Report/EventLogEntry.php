<?php

declare(strict_types=1);

namespace Vortos\Paddle\Report;

final class EventLogEntry
{
    public function __construct(
        public readonly string             $eventId,
        public readonly string             $eventType,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}
}
