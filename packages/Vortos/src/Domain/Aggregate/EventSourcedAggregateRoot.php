<?php

declare(strict_types=1);

namespace Vortos\Domain\Aggregate;

use Vortos\Domain\Event\EventEnvelope;

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
 * apply() receives the raw event payload (any object) — the same shape
 * stored in EventEnvelope::$payload. Aggregates dispatch on payload type
 * to mutate their state. The persistence layer (EventStoreRepository,
 * planned) replays envelopes against the aggregate via replay().
 */
abstract class EventSourcedAggregateRoot extends AggregateRoot
{
    /**
     * Apply a domain event payload to mutate aggregate state during replay.
     * Implementations must handle all payload types the aggregate can produce.
     *
     * Called by EventStoreRepository when reconstructing from event history.
     * Never call this directly from command methods.
     */
    abstract protected function apply(object $payload): void;

    /**
     * Reconstruct aggregate state by replaying a sequence of past envelopes.
     *
     * @param EventEnvelope[] $envelopes
     * @internal Called by EventStoreRepository only
     */
    final public function replay(array $envelopes): void
    {
        throw new \LogicException(
            'EventSourcedAggregateRoot is not yet implemented. '
                . 'Use AggregateRoot for State-Based CQRS.'
        );
    }
}
