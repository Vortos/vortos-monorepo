<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Write;

use Doctrine\DBAL\Exception\ConnectionException as DbalConnectionException;
use Doctrine\DBAL\Exception\DeadlockException as DbalDeadlockException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException as DbalForeignKeyException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException as DbalLockWaitTimeoutException;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException as DbalNotNullException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException as DbalUniqueException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException as DoctrineOptimisticLockException;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Vortos\Domain\Aggregate\AggregateRoot;
use Vortos\Domain\Identity\AggregateId;
use Vortos\Domain\Repository\Exception\OptimisticLockException;
use Vortos\PersistenceOrm\Exception\ConnectionException;
use Vortos\PersistenceOrm\Exception\DeadlockException;
use Vortos\PersistenceOrm\Exception\ForeignKeyConstraintException;
use Vortos\PersistenceOrm\Exception\LockWaitTimeoutException;
use Vortos\PersistenceOrm\Exception\NotNullConstraintException;
use Vortos\PersistenceOrm\Exception\PersistenceException;
use Vortos\PersistenceOrm\Exception\UniqueConstraintException;

/**
 * Doctrine ORM persistence store.
 *
 * Contains all persistence logic for ORM-backed write repositories.
 * Injected into user repositories by OrmRepositoryCompilerPass when
 * the repository declares #[UsesOrmEntity(YourAggregate::class)].
 *
 * Domain aggregates must extend Vortos\PersistenceOrm\Aggregate\AggregateRoot and
 * carry #[ORM\Entity] annotations — one class, no translation layer.
 *
 * ## EntityManager encapsulation
 *
 * The EntityManager is NOT exposed. User repositories cannot call persist(),
 * flush(), clear(), or any other EM method directly. All persistence goes
 * through this store's save(), delete(), and find() methods.
 * Custom queries use createQueryBuilder(), createQuery(), or getReference().
 *
 * ## Save semantics
 *
 * save() uses $em->contains() to detect insert vs update.
 * If tracked by Doctrine's identity map — flush changes.
 * If not tracked (new aggregate) — persist then flush.
 *
 * ## Exception translation
 *
 * All DBAL/Doctrine exceptions are translated to Vortos\PersistenceOrm\Exception types:
 *   UniqueConstraintViolationException  → UniqueConstraintException
 *   ForeignKeyConstraintViolation       → ForeignKeyConstraintException
 *   NotNullConstraintViolation          → NotNullConstraintException
 *   DeadlockException                   → DeadlockException (safe to retry)
 *   LockWaitTimeoutException            → LockWaitTimeoutException (safe to retry)
 *   ConnectionException                 → ConnectionException
 *   DoctrineOptimisticLockException     → domain OptimisticLockException
 *   Any other \Throwable                → PersistenceException (wrapped)
 *
 * ## Worker mode
 *
 * OrmUnitOfWork implements ResetInterface and calls $em->clear() between requests.
 * find() always loads a fresh managed entity from the database on the next request
 * — identity map state never leaks across request boundaries.
 *
 * ## flush() scope
 *
 * Each save() and delete() flushes immediately (unit-level flush). The outer
 * OrmUnitOfWork::run() flushes again after $work() returns to capture any
 * changes made by event handlers — the second flush is idempotent.
 */
final class OrmStore
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $entityClass,
    ) {}

    /**
     * Persist an aggregate — handles both insert and update.
     * Translates all Doctrine/DBAL exceptions to Vortos exception types.
     */
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
        } catch (\Throwable $e) {
            throw $this->translateException($e);
        }
    }

    /**
     * Remove an aggregate from the store.
     * Silently returns if the aggregate does not exist.
     * Translates all Doctrine/DBAL exceptions to Vortos exception types.
     */
    public function delete(AggregateRoot $aggregate): void
    {
        try {
            $managed = $this->em->contains($aggregate)
                ? $aggregate
                : $this->em->find($this->entityClass, (string) $aggregate->getId());

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
        } catch (\Throwable $e) {
            throw $this->translateException($e);
        }
    }

    /**
     * Load a single aggregate by ID. Returns null if not found.
     *
     * @return AggregateRoot|null
     */
    public function find(AggregateId $id): ?AggregateRoot
    {
        /** @var AggregateRoot|null */
        return $this->em->find($this->entityClass, (string) $id);
    }

    /**
     * Returns a QueryBuilder scoped to the entity class for DQL queries.
     * Use for complex queries that cannot be expressed via find().
     */
    public function createQueryBuilder(): QueryBuilder
    {
        return $this->em->createQueryBuilder();
    }

    /**
     * Creates a typed DQL Query object.
     * Use for queries that benefit from explicit DQL rather than the builder.
     */
    public function createQuery(string $dql): Query
    {
        return $this->em->createQuery($dql);
    }

    /**
     * Returns a proxy reference for an ID without hitting the database.
     * Use when you need to associate a related entity without loading it.
     */
    public function getReference(AggregateId $id): object
    {
        return $this->em->getReference($this->entityClass, (string) $id);
    }

    private function translateException(\Throwable $e): \Throwable
    {
        return match (true) {
            $e instanceof DbalUniqueException          => UniqueConstraintException::wrap($e),
            $e instanceof DbalForeignKeyException      => ForeignKeyConstraintException::wrap($e),
            $e instanceof DbalNotNullException         => NotNullConstraintException::wrap($e),
            $e instanceof DbalDeadlockException        => DeadlockException::wrap($e),
            $e instanceof DbalLockWaitTimeoutException => LockWaitTimeoutException::wrap($e),
            $e instanceof DbalConnectionException      => ConnectionException::wrap($e),
            $e instanceof \Doctrine\DBAL\Exception     => PersistenceException::wrap($e),
            default                                    => $e,
        };
    }
}
