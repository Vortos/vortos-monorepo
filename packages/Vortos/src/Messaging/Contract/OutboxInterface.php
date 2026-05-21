<?php

declare(strict_types=1);

namespace Vortos\Messaging\Contract;

use Vortos\Domain\Event\EventEnvelope;

/**
 * Transactional outbox contract.
 *
 * Guarantees at-least-once delivery by writing event envelopes to a database
 * table within the same transaction as the domain change. The OutboxRelayWorker
 * then reads pending rows and produces them to the broker asynchronously.
 *
 * Implementations must NOT open their own transaction — the caller owns it
 * (typically TransactionalMiddleware wrapping a command handler).
 *
 * The envelope carries everything needed for downstream routing and audit:
 * eventId, aggregate identity/version, payload type, schema version,
 * occurredAt, metadata (correlation, causation, trace, tenant, user, custom).
 */
interface OutboxInterface
{
    /**
     * Store an event envelope in the outbox table within the caller's active
     * transaction. Never call this outside a transaction boundary.
     */
    public function store(EventEnvelope $envelope, string $transportName): void;
}
