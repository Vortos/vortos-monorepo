<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Transaction;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Vortos\Persistence\Transaction\UnitOfWorkInterface;
use Vortos\Tenant\Session\TenantGucBinderInterface;

/**
 * ORM implementation of UnitOfWorkInterface.
 *
 * Uses DBAL-level transactions (beginTransaction / commit / rollBack) rather
 * than EntityManager::wrapInTransaction(). This is critical for FrankenPHP
 * worker mode: wrapInTransaction() calls $em->close() in its finally block on
 * any exception, permanently killing the EntityManager for all subsequent
 * requests in that worker thread. DBAL-level transactions never close the EM.
 *
 * ## Worker mode isolation
 *
 * The injected EntityManagerInterface is ResettableEntityManager, which owns
 * ResetInterface. Between requests, Runner::cleanUp() calls reset() on it —
 * clearing Doctrine's identity map and rebuilding the inner EntityManager (via
 * the ORM 3 constructor, on the same DBAL connection) if it was closed.
 *
 * That request-boundary reset is not enough on its own for a long-running
 * consumer, which processes many messages between two boundaries: if one
 * message closes the EM, every message after it in the same batch would inherit
 * the closed manager and fail with EntityManagerClosed — one poison message
 * dead-lettering a whole run. So run() also resets the manager in its own catch
 * when a failure left it closed, containing the damage to the message that
 * caused it rather than waiting for the next boundary.
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
final class OrmUnitOfWork implements UnitOfWorkInterface
{
    /**
     * @param TenantGucBinderInterface|null $tenantBinder Binds the tenant GUC for
     *        RLS at transaction start. Null in single-tenant apps (no tenant package).
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ?TenantGucBinderInterface $tenantBinder = null,
    ) {}

    public function run(callable $work): mixed
    {
        $this->ensureConnection();

        $conn = $this->em->getConnection();
        $conn->beginTransaction();

        try {
            // Bind the tenant GUC for RLS — transaction-scoped, auto-cleared on commit/rollback.
            $this->tenantBinder?->bindLocal();

            $result = $work();
            $this->em->flush();
            $conn->commit();

            return $result;
        } catch (\Throwable $e) {
            $conn->rollBack();

            // If the failure closed the EntityManager — a flush that hit a constraint or an
            // optimistic-lock conflict, anything Doctrine treats as unrecoverable — rebuild it
            // before returning to the caller. In a long-running consumer the SAME manager serves the
            // next message, and a closed one turns a single poison message into an
            // EntityManagerClosed cascade that dead-letters every message behind it. reset() reuses
            // the same DBAL connection, so services holding it directly (the outbox writer) are
            // untouched; it is a no-op unless the manager is actually closed. The wrapper's
            // request-boundary reset still runs, this just stops the closure leaking to the very
            // next message instead of waiting for that boundary.
            if ($this->em instanceof ResetInterface && !$this->em->isOpen()) {
                $this->em->reset();
            }

            throw $e;
        }
    }

    public function isActive(): bool
    {
        return $this->em->getConnection()->isTransactionActive();
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
