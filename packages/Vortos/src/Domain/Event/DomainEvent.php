<?php

namespace Vortos\Domain\Event;

/**
 * Abstract base class for all domain events.
 *
 * Provides the common structure every domain event must carry:
 * the aggregate ID that raised the event, the timestamp when it occurred,
 * and a schema version for forward compatibility.
 *
 * Subclasses add domain-specific payload as readonly constructor properties.
 * Never add mutable state to a domain event — events are facts, immutable
 * by definition.
 *
 * Usage:
 *   final readonly class UserRegisteredEvent extends DomainEvent
 *   {
 *       public function __construct(
 *           string $aggregateId,
 *           public readonly string $email,
 *       ) {
 *           parent::__construct($aggregateId);
 *       }
 *   }
 *
 * When the payload shape changes in a breaking way, override eventVersion():
 *   public function eventVersion(): int { return 2; }
 */
abstract readonly class DomainEvent implements DomainEventInterface
{
    private \DateTimeImmutable $occurredAt;

    public function __construct(
        private string $aggregateId,
    ) {
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function aggregateId(): string
    {
        return $this->aggregateId;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /**
     * Default version is 1.
     * Override in subclass when payload changes require a new version:
     * 
     * public function eventVersion(): int { return 2; }
     */
    public function eventVersion(): int
    {
        return 1;
    }
}