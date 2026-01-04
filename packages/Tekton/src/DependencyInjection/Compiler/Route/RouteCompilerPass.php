<?php

namespace Fortizan\Tekton\DependencyInjection\Compiler\Route;

use Fortizan\Tekton\Routing\RouteAttributeClassLoader;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Routing\RouteCollection;

class RouteCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $classLoader = new RouteAttributeClassLoader();
        $controllerIds = $container->findTaggedServiceIds('tekton.api.controller');

        $routes = new RouteCollection();

        foreach ($controllerIds as $id => $tags) {
            $class = $container->getDefinition($id)->getClass();
            $routes->addCollection($classLoader->load($class));
        }

        $definition = new Definition(RouteCollection::class);
        $definition->setSynthetic(false);
        $definition->setPublic(true);
        $definition->setFactory([self::class, 'createRouteCollection']);
        $definition->setArguments([serialize($routes)]);

        $container->setDefinition(RouteCollection::class, $definition);
    }

    /**
     * Factory method to deserialize and return routes
     */
    public static function createRouteCollection(string $serializedRoutes): RouteCollection
    {
        return unserialize($serializedRoutes);
    }
}
