<?php

declare(strict_types=1);

namespace Vortos\Cqrs\DependencyInjection\Compiler;

use ReflectionClass;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Domain\Command\AsIdempotencyKey;

/**
 * Resolves idempotency key strategies for all registered command classes.
 *
 * Runs at compile time — zero runtime reflection.
 *
 * For each command class registered in the command handler map:
 *   1. Check if the command overrides idempotencyKey() directly → strategy: 'method'
 *   2. Check if a property has #[AsIdempotencyKey] → strategy: 'property', stores property name
 *   3. Neither → strategy: 'none' (bus skips idempotency check)
 *
 * Stores the resolved strategies as a container parameter:
 *   vortos.cqrs.idempotency_strategies
 *   [
 *       RegisterUserCommand::class => ['strategy' => 'property', 'property' => 'requestId'],
 *       TransferFundsCommand::class => ['strategy' => 'method'],
 *       UpdateUserNameCommand::class => ['strategy' => 'none'],
 *   ]
 *
 * CommandBus reads this parameter once at construction — no reflection at dispatch time.
 *
 * Priority order: method override beats attribute.
 * If a command overrides idempotencyKey() AND has #[AsIdempotencyKey], the method wins.
 */
final class IdempotencyKeyPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('vortos.cqrs.command_handler_map')) {
            return;
        }

        $commandHandlerMap = $container->getParameter('vortos.cqrs.command_handler_map');
        $strategies = [];

        foreach (array_keys($commandHandlerMap) as $commandClass) {
            if (!class_exists($commandClass)) {
                continue;
            }

            $strategies[$commandClass] = $this->resolveStrategy($commandClass);
        }

        $container->setParameter('vortos.cqrs.idempotency_strategies', $strategies);
    }

    /**
     * Resolve the idempotency strategy for a command class.
     *
     * Priority: method override > #[AsIdempotencyKey] attribute > none
     */
    private function resolveStrategy(string $commandClass): array
    {
        $reflection = new ReflectionClass($commandClass);

        $hasMethodOverride = false;
        $hasAttributeProperty = null;

        // Check for method override — declared on THIS class, not inherited default
        if ($reflection->hasMethod('idempotencyKey')) {
            $method = $reflection->getMethod('idempotencyKey');
            if ($method->getDeclaringClass()->getName() === $commandClass) {
                $hasMethodOverride = true;
            }
        }

        // Check for #[AsIdempotencyKey] on a property
        foreach ($reflection->getProperties() as $property) {
            if (!empty($property->getAttributes(AsIdempotencyKey::class))) {
                $hasAttributeProperty = $property->getName();
                break;
            }
        }

        // Fail loudly if both are present — ambiguity is a programming error
        if ($hasMethodOverride && $hasAttributeProperty !== null) {
            throw new \LogicException(sprintf(
                'Command "%s" uses both #[AsIdempotencyKey] on property "$%s" '
                    . 'AND overrides idempotencyKey() method. '
                    . 'Use one or the other — remove the attribute or remove the method override.',
                $commandClass,
                $hasAttributeProperty,
            ));
        }

        if ($hasMethodOverride) {
            return ['strategy' => 'method'];
        }

        if ($hasAttributeProperty !== null) {
            return ['strategy' => 'property', 'property' => $hasAttributeProperty];
        }

        return ['strategy' => 'none'];
    }
}
