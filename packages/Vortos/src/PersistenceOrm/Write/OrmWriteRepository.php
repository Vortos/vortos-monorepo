<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Write;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException as DoctrineOptimisticLockException;
use Vortos\Domain\Aggregate\AggregateRoot;
use Vortos\Domain\Identity\AggregateId;
use Vortos\Domain\Repository\Exception\OptimisticLockException;
use Vortos\Domain\Repository\WriteRepositoryInterface;

/**
 * Abstract Doctrine ORM write repository.
 *
 * Provides findById, save, and delete backed by Doctrine's EntityManager.
 * Optimistic locking is enforced by Doctrine's #[ORM\Version] mechanism —
 * no manual version checks needed in this class.
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
 * ## Choosing ORM vs DBAL
 *
 * Use OrmWriteRepository when:
 *   - Your aggregate is a Doctrine #[ORM\Entity] with full attribute mapping
 *   - You want Doctrine to manage relations, lazy loading, or lifecycle callbacks
 *   - You prefer no-SQL aggregate persistence
 *
 * Use DbalWriteRepository when:
 *   - You want full SQL control and no entity mapping overhead
 *   - Your aggregate is reconstructed from raw rows (toRow/fromRow pattern)
 *   - You need bulk operations (batchInsert, batchUpsert)
 *
 * ## Note on flush scope
 *
 * Each save() and delete() calls flush() immediately (unit-level flush).
 * If you want to batch multiple saves before flushing, inject the
 * EntityManagerInterface directly and call flush() yourself after all saves.
 * The OrmUnitOfWork wraps the outer transaction — flush() still participates
 * in that transaction.
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
            $this->em->persist($aggregate);
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
            $this->em->remove($aggregate);
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
