<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\ObjectStore\Attribute\AsObjectStoreMiddleware;
use Vortos\ObjectStore\Middleware\HookMiddleware;
use Vortos\ObjectStore\Middleware\ObjectStoreMiddlewareStack;

final class MiddlewareCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(ObjectStoreMiddlewareStack::class)) {
            return;
        }

        $this->wireMiddlewares($container);
        $this->wireObservers($container);
    }

    private function wireMiddlewares(ContainerBuilder $container): void
    {
        $tagged = $container->findTaggedServiceIds('vortos_object_store.middleware');

        $entries = [];
        foreach ($tagged as $id => $tags) {
            $entries[] = [
                'id' => $id,
                'priority' => $tags[0]['priority'] ?? $this->readAttributePriority($container, $id),
            ];
        }

        usort($entries, static fn($a, $b): int => $b['priority'] <=> $a['priority']);

        $container->getDefinition(ObjectStoreMiddlewareStack::class)
            ->setArgument('$middlewares', array_map(static fn(array $entry): Reference => new Reference($entry['id']), $entries));
    }

    private function wireObservers(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(HookMiddleware::class)) {
            return;
        }

        $tagged = $container->findTaggedServiceIds('vortos_object_store.observer');

        $container->getDefinition(HookMiddleware::class)
            ->setArgument('$observers', array_map(static fn(string $id): Reference => new Reference($id), array_keys($tagged)));
    }

    private function readAttributePriority(ContainerBuilder $container, string $serviceId): int
    {
        $definition = $container->getDefinition($serviceId);
        $class = $definition->getClass() ?? $serviceId;

        if (!class_exists($class)) {
            return 0;
        }

        $attributes = (new \ReflectionClass($class))->getAttributes(AsObjectStoreMiddleware::class);

        return $attributes === [] ? 0 : $attributes[0]->newInstance()->priority;
    }
}
