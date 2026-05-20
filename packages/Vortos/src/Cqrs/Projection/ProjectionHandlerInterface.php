<?php

declare(strict_types=1);

namespace Vortos\Cqrs\Projection;

/**
 * Marker interface for projection handlers.
 *
 * Projection handlers consume domain events from Kafka and update
 * read models in MongoDB (or any other read store).
 *
 * ## Role in CQRS
 *
 * Write side:  Command → Handler → Aggregate → Event → Outbox → Kafka
 * Read side:   Kafka → Consumer → ProjectionHandler → ReadRepository → MongoDB
 *
 * ## Idempotency
 *
 * Projection handlers must be idempotent — they may receive the same
 * event more than once (Kafka at-least-once delivery).
 * Use upsert() on the read repository, not insert().
 *
 * ## Implementation
 *
 * Mark your projection handler with #[AsProjectionHandler] and type-hint
 * the specific event class directly — PHP cannot narrow interface parameter
 * types, so __invoke is not declared here:
 *
 *   #[AsProjectionHandler(consumer: 'user.events', handlerId: 'user.read-model')]
 *   final class UserProjectionHandler implements ProjectionHandlerInterface
 *   {
 *       public function __construct(private UserReadRepository $readRepository) {}
 *
 *       public function __invoke(UserCreatedEvent $event): void
 *       {
 *           $this->readRepository->upsert((string) $event->aggregateId(), [...]);
 *       }
 *   }
 *
 * Discovery is handled by #[AsProjectionHandler] — no additional wiring needed.
 */
interface ProjectionHandlerInterface
{
}
