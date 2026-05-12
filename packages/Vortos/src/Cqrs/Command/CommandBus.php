<?php

declare(strict_types=1);

namespace Vortos\Cqrs\Command;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Cqrs\Command\Idempotency\CommandIdempotencyStoreInterface;
use Vortos\Cqrs\Exception\CommandHandlerNotFoundException;
use Vortos\Cqrs\Validation\ValidationException;
use Vortos\Cqrs\Validation\VortosValidator;
use Vortos\Domain\Aggregate\AggregateRoot;
use Vortos\Domain\Command\CommandInterface;
use Vortos\Messaging\Contract\EventBusInterface;
use Vortos\Observability\Config\ObservabilityModule;
use Vortos\Persistence\Transaction\UnitOfWorkInterface;
use Vortos\Tracing\Contract\TracingInterface;

/**
 * Default synchronous command bus.
 *
 * ## Dispatch pipeline (in order)
 *   1. Validate          — reject invalid input before any DB/Redis work
 *   2. Idempotency check — skip if already processed
 *   3. UnitOfWork::run() — transaction wraps handler + events
 *   4. markProcessed     — record successful completion
 *
 * ## Validation memoization
 *   hasConstraints() is called at most once per command class per process lifetime.
 *   Result cached in $constraintCache — zero repeated reflection.
 *
 * ## Transaction ownership
 *   Handlers NEVER call beginTransaction/commit/rollBack.
 *   Bus owns the transaction entirely.
 */
final class CommandBus implements CommandBusInterface
{
    /** @var array<class-string, bool> */
    private array $constraintCache = [];

    /**
     * @param array              $idempotencyStrategies Compiled map from IdempotencyKeyPass.
     * @param VortosValidator|null $validator           Null = validation disabled.
     */
    public function __construct(
        private ServiceLocator $handlerLocator,
        private UnitOfWorkInterface $unitOfWork,
        private EventBusInterface $eventBus,
        private CommandIdempotencyStoreInterface $idempotencyStore,
        private LoggerInterface $logger,
        private ?TracingInterface $tracer = null,
        private array $idempotencyStrategies = [],
        private ?VortosValidator $validator = null,
    ) {}

    public function dispatch(CommandInterface $command): void
    {
        $commandClass = get_class($command);
        $shortName    = substr(strrchr($commandClass, '\\') ?: $commandClass, 1);

        if (!$this->handlerLocator->has($commandClass)) {
            throw new CommandHandlerNotFoundException($commandClass);
        }

        $span = $this->tracer?->startSpan('cqrs.command.' . $shortName, [
            'command.class' => $commandClass,
            'vortos.module' => ObservabilityModule::Cqrs,
        ]);

        try {
            // 1. Validate — before idempotency, before transaction
            $this->runValidation($command);

            // 2. Idempotency check
            $idempotencyKey = $this->resolveIdempotencyKey($command);

            if ($idempotencyKey !== null && $this->idempotencyStore->wasProcessed($idempotencyKey)) {
                $span?->setStatus('ok');
                return;
            }

            $handler = $this->handlerLocator->get($commandClass);

            // 3. Transaction
            $this->unitOfWork->run(function () use ($command, $handler): void {
                $result = $handler($command);

                if ($result instanceof AggregateRoot) {
                    foreach ($result->pullDomainEvents() as $event) {
                        $this->eventBus->dispatch($event);
                    }
                }
            });

            // 4. Mark processed — only reached on success
            if ($idempotencyKey !== null) {
                $this->idempotencyStore->markProcessed($idempotencyKey);
            }

            $this->logger->info('Command dispatched', [
                'command'  => $commandClass,
                'strategy' => $this->idempotencyStrategies[$commandClass] ?? 'none',
                'key'      => $idempotencyKey,
            ]);

            $span?->setStatus('ok');
        } catch (\Throwable $e) {
            $span?->recordException($e);
            $span?->setStatus('error');
            throw $e;
        } finally {
            $span?->end();
        }
    }

    /** @throws ValidationException */
    private function runValidation(CommandInterface $command): void
    {
        if ($this->validator === null) {
            return;
        }

        $class = get_class($command);

        if (!array_key_exists($class, $this->constraintCache)) {
            $this->constraintCache[$class] = $this->validator->hasConstraints($command);
        }

        if ($this->constraintCache[$class]) {
            $this->validator->validateOrThrow($command);
        }
    }

    private function resolveIdempotencyKey(CommandInterface $command): ?string
    {
        $strategy = $this->idempotencyStrategies[get_class($command)] ?? ['strategy' => 'none'];

        return match ($strategy['strategy']) {
            'method'   => $command->idempotencyKey(),
            'property' => (string) ($command->{$strategy['property']} ?? null),
            default    => null,
        };
    }
}
