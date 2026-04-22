<?php

declare(strict_types=1);

namespace Vortos\Cqrs\Command;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Cqrs\Command\Idempotency\CommandIdempotencyStoreInterface;
use Vortos\Cqrs\Exception\CommandHandlerNotFoundException;
use Vortos\Domain\Aggregate\AggregateRoot;
use Vortos\Domain\Command\CommandInterface;
use Vortos\Messaging\Contract\EventBusInterface;
use Vortos\Persistence\Transaction\UnitOfWorkInterface;

/**
 * Default synchronous command bus.
 *
 * ## Idempotency — zero runtime reflection
 *
 * Idempotency key strategies are resolved at compile time by IdempotencyKeyPass.
 * At runtime, the bus reads a pre-built strategy map — no reflection, no class scanning.
 *
 * Strategy types:
 *   'none'     — skip idempotency check entirely
 *   'method'   — call $command->idempotencyKey() (user-defined logic)
 *   'property' — read $command->{propertyName} directly (from #[AsIdempotencyKey])
 *
 * ## Transaction ownership
 *
 * The bus wraps every handler in UnitOfWork::run().
 * Handlers never manage transactions.
 * Events are pulled from the returned aggregate inside the transaction.
 *
 * ## Handler return value
 *
 * Handlers should return the aggregate they operated on.
 * The bus calls pullDomainEvents() on it and dispatches to EventBus.
 * Returning null is valid for commands with no domain events.
 */
final class CommandBus implements CommandBusInterface
{
    /**
     * @param array $idempotencyStrategies Compiled strategy map from IdempotencyKeyPass.
     *                                     ['CommandClass' => ['strategy' => 'property', 'property' => 'requestId']]
     */
    public function __construct(
        private ServiceLocator $handlerLocator,
        private UnitOfWorkInterface $unitOfWork,
        private EventBusInterface $eventBus,
        private CommandIdempotencyStoreInterface $idempotencyStore,
        private LoggerInterface $logger,
        private array $idempotencyStrategies = [],
    ) {}

    /**
     * {@inheritdoc}
     */
    public function dispatch(CommandInterface $command): void
    {
        $commandClass = get_class($command);

        if (!$this->handlerLocator->has($commandClass)) {
            throw new CommandHandlerNotFoundException($commandClass);
        }

        // Resolve idempotency key using pre-compiled strategy — zero reflection
        $idempotencyKey = $this->resolveIdempotencyKey($command);

        if ($idempotencyKey !== null && $this->idempotencyStore->wasProcessed($idempotencyKey)) {
            return;
        }

        $handler = $this->handlerLocator->get($commandClass);

        $this->unitOfWork->run(function () use ($command, $handler) {
            $result = $handler($command);

            if ($result instanceof AggregateRoot) {
                foreach ($result->pullDomainEvents() as $event) {
                    $this->eventBus->dispatch($event);
                }
            }
        });

        if ($idempotencyKey !== null) {
            $this->idempotencyStore->markProcessed($idempotencyKey);
        }

        $this->logger->info('Idempotency check', [
            'command' => get_class($command),
            'strategy' => $this->idempotencyStrategies[get_class($command)] ?? 'NOT IN MAP',
            'key' => $idempotencyKey,
        ]);
    }

    /**
     * Resolve the idempotency key using the pre-compiled strategy.
     *
     * No reflection at runtime — strategy was determined at compile time.
     * Reading a property via PHP's property access is direct memory access,
     * not reflection. This is effectively free.
     */
    private function resolveIdempotencyKey(CommandInterface $command): ?string
    {
        $commandClass = get_class($command);
        $strategy = $this->idempotencyStrategies[$commandClass] ?? ['strategy' => 'none'];

        return match ($strategy['strategy']) {
            'method'   => $command->idempotencyKey(),
            'property' => (string) ($command->{$strategy['property']} ?? null),
            default    => null,
        };
    }
}
