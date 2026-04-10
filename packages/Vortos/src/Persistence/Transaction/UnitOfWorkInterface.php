<?php

declare(strict_types=1);

namespace Vortos\Persistence\Transaction;

/**
 * Contract for the transaction boundary on the write side.
 *
 * The Unit of Work is responsible for:
 *   1. Opening a database transaction
 *   2. Executing application work inside that transaction
 *   3. Committing on success
 *   4. Rolling back on any exception and rethrowing
 *   5. Ensuring the connection is alive before beginning (resilience)
 *
 * ## The only public API is run()
 *
 * begin(), commit(), and rollback() are intentionally NOT on this interface.
 * Exposing them would allow callers to open transactions they never close,
 * or commit work that should have been rolled back. run() is the only safe API.
 *
 * ## Usage in ApplicationService
 *
 *   $this->unitOfWork->run(function() use ($command) {
 *       $user = User::register($command->email);
 *       $this->userRepository->save($user);
 *       foreach ($user->pullDomainEvents() as $event) {
 *           $this->outboxWriter->store($event, 'user.events');
 *       }
 *   });
 *
 * The aggregate save and outbox write are atomic — both commit or both roll back.
 *
 * ## Connection resilience
 *
 * Implementations must ping the connection before begin() and reconnect if stale.
 * This is critical for FrankenPHP worker mode and Kafka consumer workers —
 * long-running processes idle overnight and database connections time out.
 * Without resilience, workers crash silently after the first idle period.
 */
interface UnitOfWorkInterface
{
    /**
     * Execute $work inside a database transaction.
     *
     * Sequence:
     *   1. Ping connection — reconnect if stale
     *   2. BEGIN TRANSACTION
     *   3. Call $work()
     *   4. COMMIT
     *   5. Return whatever $work returned
     *
     * On any exception from $work:
     *   1. ROLLBACK (if transaction is still active)
     *   2. Rethrow the original exception
     *
     * @param callable $work Any callable. Receives no arguments.
     *                       Return value is passed through to the caller.
     *
     * @return mixed Whatever $work returns
     *
     * @throws \Throwable Rethrows anything $work throws, after rolling back
     */
    public function run(callable $work): mixed;

    /**
     * Whether a transaction is currently active on this unit of work.
     *
     * Used by TransactionalMiddleware to avoid opening a nested transaction
     * when ApplicationService has already opened the outer transaction.
     *
     * If this returns true, TransactionalMiddleware passes through without
     * calling run() — it lets the outer transaction own the commit/rollback.
     *
     * @return bool True if a transaction is currently open
     */
    public function isActive(): bool;
}
