<?php

namespace Vortos\Domain\Aggregate;

use Vortos\Messaging\Contract\DomainEventInterface;

/**
 * Stub base class for Event Sourced aggregates.
 *
 * PLANNED — not yet implemented. Do not extend in production code.
 * Use AggregateRoot for State-Based CQRS instead.
 *
 * When implemented, aggregates extending this class will never mutate
 * state directly in command methods. State changes happen exclusively
 * inside apply() methods, called during event replay. This allows the
 * aggregate to be fully reconstructed from its event history alone.
 *
 * The persistence layer will provide EventStoreRepository as the
 * corresponding repository implementation.
 */
abstract class EventSourcedAggregateRoot extends AggregateRoot
{
    /**
     * Apply a domain event to mutate aggregate state during replay.
     * Implementations must handle all event types the aggregate can produce.
     * 
     * Called by EventStoreRepository when reconstructing from event history.
     * Never call this directly from command methods.
     */
    abstract protected function apply(DomainEventInterface $event): void;

    /**
     * Reconstruct aggregate state by replaying a sequence of past events.
     * 
     * @param DomainEventInterface[] $events
     * @internal Called by EventStoreRepository only
     */
    final public function replay(array $events): void
    {
        throw new \LogicException(
            'EventSourcedAggregateRoot is not yet implemented. ' .
                'Use AggregateRoot for State-Based CQRS.'
        );
    }
}