<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Attribute;

use Attribute;

/**
 * Marks a method or class as an event handler within a named consumer pipeline.
 *
 * When placed on a class, the class must have an __invoke method which becomes
 * the handler. When placed on a method, that specific method is the handler.
 * Multiple methods in the same class can each have this attribute for different events.
 *
 * The first non-attribute parameter of the handler method must be a class
 * implementing DomainEventInterface — this determines which event type is handled.
 * The compiler pass validates this at container compile time.
 *
 * Example (class-level):
 *   #[AsEventHandler(handlerId: 'order.placed.notify', consumer: 'orders.placed')]
 *   final class HandleOrderPlaced
 *   {
 *       public function __invoke(OrderPlaced $event): void {}
 *   }
 *
 * Example (method-level):
 *   final class OrderEventHandlers
 *   {
 *       #[AsEventHandler(handlerId: 'order.placed.notify', consumer: 'orders.placed')]
 *       public function onOrderPlaced(OrderPlaced $event): void {}
 *
 *       #[AsEventHandler(handlerId: 'order.shipped.notify', consumer: 'orders.shipped')]
 *       public function onOrderShipped(OrderShipped $event): void {}
 *   }
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class AsEventHandler
{
    public function __construct(
        /** This is used in event routing */
        public readonly string $handlerId,

        /** The consumer name this handler is registered to (as defined in MessagingConfig). */
        public readonly string $consumer,

        /** Execution priority within this consumer. Higher = runs first. */
        public readonly int $priority = 0,

        /** Is this handler safe to retry? Allows middleware to skip deduplication. */
        public readonly bool $idempotent = false,

        /** Optional: Filter by event schema version. */
        public readonly ?int $version = null,
    ) {}
}