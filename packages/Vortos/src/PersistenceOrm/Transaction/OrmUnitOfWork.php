<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Transaction;

use Doctrine\ORM\EntityManagerInterface;
use Vortos\Persistence\Transaction\UnitOfWorkInterface;

/**
 * ORM implementation of UnitOfWorkInterface.
 *
 * Delegates transaction management to Doctrine's EntityManager.
 * wrapInTransaction() handles begin, commit, and rollback — including
 * rollback on any exception thrown inside $work.
 *
 * ## Connection resilience
 *
 * EntityManager::wrapInTransaction() uses the underlying DBAL Connection.
 * For long-running workers (FrankenPHP, Kafka consumers), the connection
 * can go stale. The EntityManager will reconnect automatically on the next
 * query if the connection dropped — DBAL handles this transparently in 4.x.
 *
 * ## Nested transactions
 *
 * If OrmUnitOfWork is nested inside another OrmUnitOfWork (or a DBAL UnitOfWork
 * sharing the same connection), Doctrine uses savepoints automatically.
 * The outermost run() owns the final commit/rollback.
 */
final class OrmUnitOfWork implements UnitOfWorkInterface
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function run(callable $work): mixed
    {
        return $this->em->wrapInTransaction($work);
    }

    public function isActive(): bool
    {
        return $this->em->getConnection()->isTransactionActive();
    }
}
