<?php

declare(strict_types=1);

namespace Vortos\Messaging\Contract;

use Vortos\Domain\Event\EventEnvelope;

/**
 * The internal in-process event bus.
 *
 * Accepts EventEnvelopes produced by aggregates (via pullDomainEvents() → EventEnvelope[]) and
 * routes them through three independent paths:
 *
 *   1. In-process handlers (via Symfony Messenger) — if any are registered
 *      for the payload type.
 *   2. Outbox (if a producer is configured for the payload type and
 *      outbox is enabled).
 *   3. Direct producer (if outbox is disabled for the producer).
 *
 * The bus enriches envelope Metadata at dispatch (correlation from tracer,
 * default trace id) before any of those paths run. Hooks observe and may
 * mutate the wire headers used by the producer.
 */
interface EventBusInterface
{
    /**
     * Dispatch a single envelope through the bus.
     * Handlers, outbox, and producer routing all happen here.
     */
    public function dispatch(EventEnvelope $envelope): void;

    /**
     * Dispatch multiple envelopes sequentially.
     * All envelopes share the same transaction context when called inside
     * TransactionalMiddleware.
     */
    public function dispatchBatch(EventEnvelope ...$envelopes): void;
}
