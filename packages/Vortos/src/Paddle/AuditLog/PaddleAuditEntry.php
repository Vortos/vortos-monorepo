<?php

declare(strict_types=1);

namespace Vortos\Paddle\AuditLog;

final class PaddleAuditEntry
{
    public function __construct(
        public readonly string             $eventType,
        public readonly string             $paddleEventId,
        public readonly string             $entityType,
        public readonly string             $entityId,
        public readonly ?string            $actor,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}
}
