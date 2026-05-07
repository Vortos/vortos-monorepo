<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Transaction;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Vortos\Persistence\Transaction\UnitOfWorkInterface;

/**
 * ORM implementation of UnitOfWorkInterface.
 *
 * Delegates transaction management to Doctrine's EntityManager via
 * wrapInTransaction(), which handles begin, commit, and rollback — including
 * automatic rollback on any exception thrown inside $work.
 *
 * ## Worker mode isolation (ResetInterface)
 *
 * Implements ResetInterface so ResettableServicesPass discovers it automatically.
 * Between requests, Runner::cleanUp() calls ServicesResetter::reset(), which
 * calls reset() here. This clears Doctrine's identity map, detaching all
 * entities and preventing any aggregate state from leaking across requests.
 *
 * ## Connection resilience
 *
 * ensureConnection() pings the database before beginning a transaction. If the
 * connection is stale (common in FrankenPHP workers after idle periods), it
 * calls close() to reset DBAL's internal state. DBAL 4.x reconnects automatically
 * on the next query after close(). This mirrors the pattern in PersistenceDbal's
 * UnitOfWork and is critical for long-running worker processes.
 *
 * ## Nested transactions
 *
 * If OrmUnitOfWork is nested inside another UnitOfWork sharing the same
 * connection, Doctrine uses savepoints automatically. The outermost run()
 * owns the final commit/rollback.
 */
final class OrmUnitOfWork implements UnitOfWorkInterface, ResetInterface
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function run(callable $work): mixed
    {
        $this->ensureConnection();

        return $this->em->wrapInTransaction($work);
    }

    public function isActive(): bool
    {
        return $this->em->getConnection()->isTransactionActive();
    }

    public function reset(): void
    {
        $this->em->clear();
    }

    private function ensureConnection(): void
    {
        try {
            $this->em->getConnection()->executeQuery('SELECT 1');
        } catch (\Throwable) {
            $this->em->getConnection()->close();
        }
    }
}
