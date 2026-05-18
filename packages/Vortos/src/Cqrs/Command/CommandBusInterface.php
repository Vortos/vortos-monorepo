<?php

declare(strict_types=1);

namespace Vortos\Cqrs\Command;

use Vortos\Domain\Command\CommandInterface;

/**
 * Contract for the command bus.
 *
 * The command bus routes a command to exactly one handler.
 * It is the single entry point for all write operations in the application.
 *
 * ## Responsibilities
 *
 * Before dispatching to the handler, the command bus:
 *   1. Checks idempotency — if this command was already processed, skip
 *   2. Opens a transaction via UnitOfWork
 *   3. Calls the handler
 *   4. Pulls domain events from the returned aggregate
 *   5. Dispatches events to EventBus (writes to outbox inside transaction)
 *   6. Commits the transaction
 *
 * On failure:
 *   1. Rolls back the transaction
 *   2. Rethrows the exception
 *
 * ## Usage
 *
 *   $commandBus->dispatch(new RegisterUserCommand(
 *       email: 'alice@example.com',
 *       name: 'Alice',
 *   ));
 *
 * The caller never manages transactions. The bus owns the transaction boundary.
 *
 * ## Idempotency
 *
 * Every command implements CommandInterface::idempotencyKey().
 * If the same idempotency key is dispatched twice within the TTL window,
 * the second dispatch is silently skipped — no handler called, no exception.
 * This protects against duplicate form submissions and retry storms.
 */
interface CommandBusInterface
{
    /**
     * Dispatch a command to its registered handler.
     *
     * Returns whatever the handler returns — typically an aggregate root, a DTO,
     * or null. Callers that do not need the return value may ignore it.
     *
     * @param CommandInterface $command The command to dispatch
     *
     * @throws CommandHandlerNotFoundException If no handler is registered for this command
     * @throws DuplicateCommandException       If idempotency key was already processed (strict mode only)
     * @throws \Throwable                      Rethrows any exception from the handler after rollback
     */
    public function dispatch(CommandInterface $command): mixed;
}
