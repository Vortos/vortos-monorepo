<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Write;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException as DoctrineOptimisticLockException;
use Vortos\Domain\Aggregate\AggregateRoot;
use Vortos\Domain\Identity\AggregateId;
use Vortos\Domain\Repository\Exception\OptimisticLockException;
use Vortos\Domain\Repository\WriteRepositoryInterface;

/**
 * Abstract Doctrine ORM write repository.
 *
 * Domain aggregates extend Vortos\PersistenceOrm\Aggregate\AggregateRoot and
 * carry #[ORM\Entity] annotations directly — one class, no translation layer.
 * This repository delegates all persistence to the EntityManager and translates
 * Doctrine's optimistic lock exception into the domain exception.
 *
 * ## Usage
 *
 *   final class UserRepository extends OrmWriteRepository
 *   {
 *       protected function entityClass(): string
 *       {
 *           return User::class;
 *       }
 *   }
 *
 * ## Save semantics
 *
 * save() inspects whether the aggregate is currently tracked by Doctrine's
 * identity map ($em->contains()). If tracked, it flushes changes. If not
 * tracked (new aggregate), it persists then flushes. This handles both insert
 * and update without requiring callers to distinguish between the two.
 *
 * ## Optimistic locking
 *
 * Doctrine enforces #[ORM\Version] locking automatically on UPDATE. On conflict
 * it throws DoctrineOptimisticLockException, which is caught here and translated
 * to the domain OptimisticLockException with the expected version from the exception.
 *
 * ## Worker mode
 *
 * OrmUnitOfWork implements ResetInterface and calls $em->clear() between
 * requests, detaching all entities. findById() always loads a fresh managed
 * entity from the database on the next request — identity map state never
 * leaks across request boundaries.
 *
 * ## flush() scope
 *
 * Each save() and delete() flushes immediately (unit-level flush). To batch
 * multiple saves before a single flush, inject EntityManagerInterface directly
 * and call flush() yourself after all operations. The OrmUnitOfWork transaction
 * wraps everything — flush() still participates in the outer transaction.
 */
abstract class OrmWriteRepository implements WriteRepositoryInterface
{
    public function __construct(protected readonly EntityManagerInterface $em) {}

    abstract protected function entityClass(): string;

    public function findById(AggregateId $id): ?AggregateRoot
    {
        return $this->em->find($this->entityClass(), (string) $id);
    }

    public function save(AggregateRoot $aggregate): void
    {
        try {
            if (!$this->em->contains($aggregate)) {
                $this->em->persist($aggregate);
            }

            $this->em->flush();
        } catch (DoctrineOptimisticLockException $e) {
            throw OptimisticLockException::forAggregate(
                get_class($aggregate),
                (string) $aggregate->getId(),
                $aggregate->getVersion(),
                -1,
            );
        }
    }

    public function delete(AggregateRoot $aggregate): void
    {
        try {
            $managed = $this->em->contains($aggregate)
                ? $aggregate
                : $this->em->find($this->entityClass(), (string) $aggregate->getId());

            if ($managed === null) {
                return;
            }

            $this->em->remove($managed);
            $this->em->flush();
        } catch (DoctrineOptimisticLockException $e) {
            throw OptimisticLockException::forAggregate(
                get_class($aggregate),
                (string) $aggregate->getId(),
                $aggregate->getVersion(),
                -1,
            );
        }
    }

    protected function em(): EntityManagerInterface
    {
        return $this->em;
    }
}
