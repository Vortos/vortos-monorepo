<?php

declare(strict_types=1);

namespace Vortos\Cqrs\DependencyInjection\Compiler;

use ReflectionClass;
use ReflectionNamedType;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Discovers all classes tagged with #[AsQueryHandler].
 * Infers the query class from __invoke() parameter type if 'handles' is not set.
 * Validates no duplicate handlers.
 * Injects the handler map into the query bus ServiceLocator.
 */
final class QueryHandlerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $taggedHandlers = $container->findTaggedServiceIds('vortos.query_handler');

        $locatorMap = [];

        foreach ($taggedHandlers as $serviceId => $tags) {
            foreach ($tags as $tag) {

                $queryClass = $this->inferQueryClass($serviceId, $container);

                if (isset($locatorMap[$queryClass])) {
                    throw new \LogicException(sprintf(
                        'Two query handlers registered for "%s": "%s" and a new handler. '
                            . 'Each query must have exactly one handler.',
                        $queryClass,
                        (string) $locatorMap[$queryClass],
                    ));
                }

                $locatorMap[$queryClass] = new Reference($serviceId);
            }
        }

        if (!$container->hasDefinition('vortos.query_handler_locator')) {
            return;
        }

        $container->getDefinition('vortos.query_handler_locator')
            ->setArguments([$locatorMap]);
    }

    private function inferQueryClass(string $serviceId, ContainerBuilder $container): string
    {
        $className = $container->getDefinition($serviceId)->getClass();
        $reflection = new ReflectionClass($className);

        if (!$reflection->hasMethod('__invoke')) {
            throw new \LogicException(sprintf(
                'Query handler "%s" must have an __invoke() method.',
                $className,
            ));
        }

        $params = $reflection->getMethod('__invoke')->getParameters();

        if (empty($params)) {
            throw new \LogicException(sprintf(
                'Query handler "%s" __invoke() must have at least one parameter (the query).',
                $className,
            ));
        }

        $type = $params[0]->getType();

        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            throw new \LogicException(sprintf(
                'Query handler "%s" __invoke() first parameter must have a class type hint.',
                $className,
            ));
        }

        return $type->getName();
    }
}
