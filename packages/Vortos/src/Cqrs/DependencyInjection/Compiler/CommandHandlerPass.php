<?php

declare(strict_types=1);

namespace Vortos\Cqrs\DependencyInjection\Compiler;

use ReflectionClass;
use ReflectionNamedType;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Discovers all classes tagged with #[AsCommandHandler] and:
 *   1. Infers the command class from __invoke() parameter type if 'handles' tag is null
 *   2. Validates no duplicate handlers
 *   3. Stores the command → serviceId map as 'vortos.cqrs.command_handler_map' parameter
 *   4. Injects the map into the command bus ServiceLocator
 *
 * The 'handles' tag attribute is OPTIONAL.
 * If not provided, the command class is inferred from the __invoke() first parameter type.
 * This means #[AsCommandHandler] with no arguments works for most handlers.
 */
final class CommandHandlerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $taggedHandlers = $container->findTaggedServiceIds('vortos.command_handler');

        $handlerMap = [];    // commandClass => serviceId (for parameter storage)
        $locatorMap = [];    // commandClass => Reference (for ServiceLocator)

        foreach ($taggedHandlers as $serviceId => $tags) {
            foreach ($tags as $tag) {

                $commandClass = $this->inferCommandClass($serviceId, $container);

                if (isset($handlerMap[$commandClass])) {
                    throw new \LogicException(sprintf(
                        'Two command handlers registered for "%s": "%s" and "%s". '
                            . 'Each command must have exactly one handler.',
                        $commandClass,
                        $handlerMap[$commandClass],
                        $serviceId,
                    ));
                }

                $handlerMap[$commandClass] = $serviceId;
                $locatorMap[$commandClass] = new Reference($serviceId);
            }
        }

        // Store map as parameter — IdempotencyKeyPass reads this
        $container->setParameter('vortos.cqrs.command_handler_map', $handlerMap);

        if (!$container->hasDefinition('vortos.command_handler_locator')) {
            return;
        }

        $container->getDefinition('vortos.command_handler_locator')
            ->setArguments([$locatorMap]);
    }

    /**
     * Infer the command class from the handler's __invoke() first parameter type.
     *
     * @throws \LogicException if __invoke() is missing or has no typed first parameter
     */
    private function inferCommandClass(string $serviceId, ContainerBuilder $container): string
    {
        $className = $container->getDefinition($serviceId)->getClass();
// var_dump("---------------------------".$className . "------------------");
        if ($className === null) {
            throw new \LogicException(sprintf(
                'Cannot infer command class for service "%s" — class is null.',
                $serviceId,
            ));
        }

        $reflection = new ReflectionClass($className);

        if (!$reflection->hasMethod('__invoke')) {
            throw new \LogicException(sprintf(
                'Command handler "%s" must have an __invoke() method. '
                    . 'Either add __invoke() or set handles: explicitly on #[AsCommandHandler].',
                $className,
            ));
        }

        $params = $reflection->getMethod('__invoke')->getParameters();

        if (empty($params)) {
            throw new \LogicException(sprintf(
                'Command handler "%s" __invoke() must have at least one parameter (the command).',
                $className,
            ));
        }

        $type = $params[0]->getType();

        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            throw new \LogicException(sprintf(
                'Command handler "%s" __invoke() first parameter must have a class type hint.',
                $className,
            ));
        }

        return $type->getName();
    }
}
