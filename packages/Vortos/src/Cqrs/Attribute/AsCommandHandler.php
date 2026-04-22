<?php

declare(strict_types=1);

namespace Vortos\Cqrs\Attribute;

use Attribute;

/**
 * Marks a class as a command handler.
 *
 * ## Minimal usage — zero boilerplate:
 *
 *   #[AsCommandHandler]
 *   final class RegisterUserHandler
 *   {
 *       public function __construct(private UserRepository $repository) {}
 *
 *       public function __invoke(RegisterUserCommand $command): User
 *       {
 *           $user = User::registerUser($command->name, $command->email);
 *           $this->repository->save($user);
 *           return $user;
 *       }
 *   }
 *
 * The command class is inferred from the __invoke() first parameter type.
 * No need to specify handles: unless you want to be explicit.
 *
 * ## Explicit usage:
 *
 *   #[AsCommandHandler(handles: RegisterUserCommand::class)]
 *
 * Use explicit form when:
 *   - The handler class name differs significantly from the command name
 *   - You want to make the relationship obvious in code review
 *   - IDE navigation matters to you
 *
 * ## What the command bus does automatically:
 *   - Opens a transaction (UnitOfWork::run)
 *   - Calls your __invoke()
 *   - Pulls domain events from the returned aggregate
 *   - Dispatches events to EventBus (outbox inside transaction)
 *   - Commits
 *   - Marks idempotency key as processed (if applicable)
 *
 * Your handler just does domain logic and returns the aggregate.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AsCommandHandler
{
}
