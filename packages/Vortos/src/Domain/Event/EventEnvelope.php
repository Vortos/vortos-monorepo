<?php

declare(strict_types=1);

namespace Vortos\Domain\Event;

/**
 * Immutable wrapper around a domain event payload.
 *
 * The envelope separates business facts (the payload — a pure POPO with no
 * framework dependencies) from cross-cutting concerns (event id, aggregate
 * identity, timing, correlation). Payloads have no methods, no inheritance,
 * no marker interface. The envelope is constructed by AggregateRoot::recordEvent
 * when an event is recorded and travels alongside the payload through the
 * outbox, the broker, and into consumer handlers.
 *
 * Lives in the Domain layer because aggregates produce envelopes directly —
 * Domain cannot depend on Messaging, but Messaging consumes envelopes.
 *
 * Handlers receive both: the typed payload as their first parameter, and
 * optionally the envelope or Metadata via type-based injection by the
 * HandlerDiscoveryCompilerPass / ProjectionDiscoveryCompilerPass.
 *
 * Envelopes are immutable. Use withMetadata() to create a new instance with
 * enriched metadata — typically when EventBus dispatch enriches correlation
 * from the tracer or hooks add tenant context.
 */
final readonly class EventEnvelope
{
    public function __construct(
        public string $eventId,
        public string $aggregateId,
        public string $aggregateType,
        public int $aggregateVersion,
        public string $payloadType,
        public int $schemaVersion,
        public \DateTimeImmutable $occurredAt,
        public object $payload,
        public Metadata $metadata,
    ) {}

    public function withMetadata(Metadata $metadata): self
    {
        return new self(
            $this->eventId,
            $this->aggregateId,
            $this->aggregateType,
            $this->aggregateVersion,
            $this->payloadType,
            $this->schemaVersion,
            $this->occurredAt,
            $this->payload,
            $metadata,
        );
    }
}
