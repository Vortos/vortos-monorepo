<?php

declare(strict_types=1);

namespace Vortos\Alerts\Integration\Messaging;

/**
 * A point-in-time backlog reading for one queue-ish surface (an outbox transport, a DLQ transport).
 */
final readonly class QueueBacklog
{
    public function __construct(
        public string $queue,
        public int $depth,
        public ?int $oldestAgeSeconds = null,
    ) {}
}
