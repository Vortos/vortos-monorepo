<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Transaction;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Vortos\Persistence\Transaction\UnitOfWorkInterface;

/**
 * ORM implementation of UnitOfWorkInterface.
 *
 * Uses DBAL-level transactions (beginTransaction / commit / rollBack) rather
 * than EntityManager::wrapInTransaction(). This is critical for FrankenPHP
 * worker mode: wrapInTransaction() calls $em->close() in its finally block on
 * any exception, permanently killing the EntityManager for all subsequent
 * requests in that worker thread. DBAL-level transactions never close the EM.
 *
 * ## Worker mode isolation (ResetInterface)
 *
 * Implements ResetInterface so ResettableServicesPass discovers it automatically.
 * Between requests, Runner::cleanUp() calls ServicesResetter::reset(), which
 * calls reset() here. reset() clears Doctrine's identity map and, if the EM was
 * closed by an unexpected path, reopens it via EntityManager::create() — ensuring
 * the next request always starts with a healthy EM.
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

        $conn = $this->em->getConnection();
        $conn->beginTransaction();

        try {
            $result = $work();
            $this->em->flush();
            $conn->commit();

            return $result;
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    public function isActive(): bool
    {
        return $this->em->getConnection()->isTransactionActive();
    }

    public function reset(): void
    {
        if (!$this->em->isOpen()) {
            // EM was closed by an unexpected code path — reopen for next request.
            // This is a safety net; the DBAL-level transaction strategy should
            // prevent the EM from ever being closed in normal operation.
            $this->em->getConnection()->close();
        }

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
