<?php

declare(strict_types=1);

namespace Vortos\Persistence\Write;

use Vortos\Domain\Aggregate\AggregateRoot;
use Vortos\Domain\Identity\AggregateId;
use Vortos\Domain\Repository\Exception\OptimisticLockException;
use Vortos\Domain\Repository\WriteRepositoryInterface;

/**
 * In-memory write repository for testing.
 *
 * Stores clones of aggregates — not references.
 * This matches real database behaviour: after save(), mutating the aggregate
 * in memory does not change what is stored. A fresh load is required.
 *
 * ## Optimistic locking is enforced
 *
 * Even in tests, OptimisticLockException is thrown on version conflicts.
 * Tests that load an aggregate, modify it, and save it must handle concurrent
 * modification the same way production code does. If InMemory didn't enforce
 * this, version conflict bugs would only appear in production.
 *
 * ## Usage in tests
 *
 *   final class InMemoryUserRepository extends InMemoryWriteRepository {}
 *
 *   // In test:
 *   $repository = new InMemoryUserRepository();
 *   $user = User::register('test@example.com');
 *   $repository->save($user);
 *
 *   $loaded = $repository->findById($user->getId());
 *   // $loaded is a clone — mutating it does not affect the store
 *
 *   // Assert state:
 *   $this->assertCount(1, $repository->all());
 *
 *   // Reset between tests:
 *   $repository->clear();
 */
abstract class InMemoryWriteRepository implements WriteRepositoryInterface
{
    /**
     * Internal store keyed by string aggregate ID.
     * Values are clones — never live references.
     *
     * @var array<string, AggregateRoot>
     */
    private array $store = [];

    /**
     * Find an aggregate by ID.
     *
     * Returns a clone — mutating the returned aggregate does not
     * affect the stored version. Call save() to persist changes.
     *
     * {@inheritdoc}
     */
    public function findById(AggregateId $id): ?AggregateRoot
    {
        $key = (string) $id;

        if (!isset($this->store[$key])) {
            return null;
        }

        return clone $this->store[$key];
    }

    /**
     * Persist an aggregate.
     *
     * Stores a clone to prevent external mutation of stored state.
     * Enforces optimistic locking — throws if the version in the store
     * does not match the version on the aggregate being saved.
     *
     * {@inheritdoc}
     */
    public function save(AggregateRoot $aggregate): void
    {
        $key = (string) $aggregate->getId();

        if (isset($this->store[$key])) {
            $storedVersion = $this->store[$key]->getVersion();
            $expectedVersion = $aggregate->getVersion();

            if ($storedVersion !== $expectedVersion) {
                throw OptimisticLockException::forAggregate(
                    get_class($aggregate),
                    $key,
                    $expectedVersion,
                    $storedVersion,
                );
            }
        }

        $this->store[$key] = clone $aggregate;
        $aggregate->incrementVersion();
    }

    /**
     * Remove an aggregate from the store.
     *
     * Silent if the aggregate does not exist — matches real DB behaviour
     * where DELETE of a non-existent row affects zero rows.
     *
     * {@inheritdoc}
     */
    public function delete(AggregateRoot $aggregate): void
    {
        unset($this->store[(string) $aggregate->getId()]);
    }

    /**
     * Return all stored aggregates as a flat array.
     *
     * For test assertions only — not on WriteRepositoryInterface.
     * Returns clones to prevent test code from mutating stored state.
     *
     * @return AggregateRoot[]
     */
    public function all(): array
    {
        return array_map(fn(AggregateRoot $a) => clone $a, array_values($this->store));
    }

    /**
     * Reset the store to empty.
     *
     * Call in test setUp() or tearDown() to ensure test isolation.
     * Never call this in production code.
     */
    public function clear(): void
    {
        $this->store = [];
    }
}
