<?php

namespace Vortos\Domain\Repository;

use Vortos\Domain\Aggregate\AggregateRoot;
use Vortos\Domain\Identity\AggregateId;

/**
 * Contract for aggregate write repositories.
 *
 * Defined in the domain layer — implementations live in infrastructure.
 * The domain never depends on DBAL, ORM, or any persistence technology.
 * Only this interface is visible to application layer code.
 *
 * The default implementation is DbalWriteRepository in vortos-persistence.
 * Doctrine ORM implementation is available in vortos-persistence-orm.
 * InMemoryWriteRepository ships for testing.
 */
interface WriteRepositoryInterface
{
    /**
     * Find an aggregate by its ID.
     * Returns null if not found — never throws for missing records.
     */
    public function findById(AggregateId $id): ?AggregateRoot;

    /**
     * Persist an aggregate.
     * Handles both insert (new aggregate) and update (existing).
     * Increments the aggregate's version after successful save.
     * 
     * @throws OptimisticLockException if version conflict detected
     */
    public function save(AggregateRoot $aggregate): void;

    /**
     * Remove an aggregate from the store.
     * Soft delete vs hard delete is an infrastructure concern —
     * implementations decide. Domain just calls delete().
     */
    public function delete(AggregateRoot $aggregate): void;
}