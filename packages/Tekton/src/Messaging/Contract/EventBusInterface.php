<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Contract;

/**
 * The internal in-process event bus.
 *
 * Responsible for dispatching domain events through the middleware pipeline
 * to all registered handlers. Does not communicate with any broker directly.
 * Outbox routing, tracing, and idempotency are handled by middleware.
 */
interface EventBusInterface
{
    /**
     * Dispatch a single domain event through the middleware pipeline.
     * All handlers registered to the event's consumer will be invoked.
     */
    public function dispatch(DomainEventInterface $event):void;

    /**
     * Dispatch multiple domain events sequentially.
     * All events share the same transaction context when wrapped
     * in TransactionalMiddleware.
     */
    public function dispatchBatch(DomainEventInterface ...$event):void;
}
