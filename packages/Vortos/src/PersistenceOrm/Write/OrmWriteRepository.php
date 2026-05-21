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
use Vortos\Domain\Aggregate\AggregateRoot;
use Vortos\Domain\Identity\AggregateId;
use Vortos\Domain\Repository\Exception\OptimisticLockException;
use Vortos\Domain\Repository\WriteRepositoryInterface;
use Vortos\PersistenceOrm\Exception\ConnectionException;
use Vortos\PersistenceOrm\Exception\DeadlockException;
use Vortos\PersistenceOrm\Exception\ForeignKeyConstraintException;
use Vortos\PersistenceOrm\Exception\LockWaitTimeoutException;
use Vortos\PersistenceOrm\Exception\NotNullConstraintException;
use Vortos\PersistenceOrm\Exception\PersistenceException;
use Vortos\PersistenceOrm\Exception\UniqueConstraintException;

/**
 * Abstract Doctrine ORM write repository.
 *
 * Domain aggregates extend Vortos\PersistenceOrm\Aggregate\AggregateRoot and
 * carry #[ORM\Entity] annotations directly — one class, no translation layer.
 * This repository delegates all persistence to the EntityManager and translates
 * all Doctrine/DBAL exceptions into the Vortos\PersistenceOrm\Exception hierarchy
 * so application and domain code never imports Doctrine or DBAL types.
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
 * ## Exception translation
 *
 * All DBAL exceptions are caught and translated to Vortos types:
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
 * OrmUnitOfWork implements ResetInterface and calls $em->clear() between
 * requests, detaching all entities. findById() always loads a fresh managed
 * entity from the database on the next request — identity map state never
 * leaks across request boundaries.
 *
 * ## flush() scope
 *
 * Each save() and delete() flushes immediately (unit-level flush). The outer
 * OrmUnitOfWork::run() flushes again after $work() returns to capture any
 * changes made by event handlers — this second flush is idempotent if save()
 * already flushed everything.
 */
abstract class OrmWriteRepository implements WriteRepositoryInterface
{
    public function __construct(private readonly EntityManagerInterface $em) {}

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
        } catch (\Throwable $e) {
            throw $this->translateException($e);
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
        } catch (\Throwable $e) {
            throw $this->translateException($e);
        }
    }

    private function translateException(\Throwable $e): \Throwable
    {
        return match (true) {
            $e instanceof DbalUniqueException        => UniqueConstraintException::wrap($e),
            $e instanceof DbalForeignKeyException    => ForeignKeyConstraintException::wrap($e),
            $e instanceof DbalNotNullException       => NotNullConstraintException::wrap($e),
            $e instanceof DbalDeadlockException      => DeadlockException::wrap($e),
            $e instanceof DbalLockWaitTimeoutException => LockWaitTimeoutException::wrap($e),
            $e instanceof DbalConnectionException    => ConnectionException::wrap($e),
            $e instanceof \Doctrine\DBAL\Exception   => PersistenceException::wrap($e),
            default                                  => $e,
        };
    }

    protected function em(): EntityManagerInterface
    {
        return $this->em;
    }
}
