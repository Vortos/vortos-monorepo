<?php

namespace Vortos\Domain\Event;

/**
 * Contract for all domain events in the Vortos framework.
 *
 * A domain event is an immutable record of something that happened
 * in the domain. It is named in the past tense, carries the aggregate ID
 * that raised it, and includes a schema version for consumer compatibility.
 *
 * This interface is defined in vortos-domain so aggregates can record
 * events without depending on any infrastructure module.
 * vortos-messaging depends on this interface, never the reverse.
 */
interface DomainEventInterface
{
    /**
     * The ID of the aggregate that raised this event.
     * Used for event correlation and replay ordering.
     */
    public function aggregateId(): string;

    /**
     * When this event occurred in the domain.
     * Set at construction time, never mutated.
     */
    public function occurredAt(): \DateTimeImmutable;

    /**
     * Schema version of this event.
     * Increment when the event's payload shape changes in a breaking way.
     * Consumers use this to route to the correct upcaster/handler version.
     * 
     * Start at 1. Never use 0.
     */
    public function eventVersion(): int;
}