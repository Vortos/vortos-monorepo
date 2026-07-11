<?php

declare(strict_types=1);

namespace Vortos\Audit\Ingestion;

use Vortos\Audit\Event\AuditEvent;

/**
 * Wire contract carried over the message bus from the request path (producer) to the
 * ingestion consumer. A plain, final readonly POPO — the consumer's handler deserializes
 * the payload back into an {@see AuditEvent} and appends it to its chain.
 *
 * It transports the fully-assembled event as an array so the chain is computed exactly
 * once, in the consumer, under the per-chain lock.
 */
final readonly class AuditEventRecorded
{
    /**
     * @param array<string, mixed> $event AuditEvent::toArray()
     */
    public function __construct(
        public string $auditId,
        public array  $event,
    ) {}

    public static function fromEvent(AuditEvent $event): self
    {
        return new self($event->id, $event->toArray());
    }

    public function toEvent(): AuditEvent
    {
        return AuditEvent::fromArray($this->event);
    }
}
