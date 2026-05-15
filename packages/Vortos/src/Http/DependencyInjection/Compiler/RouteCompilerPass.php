<?php

declare(strict_types=1);

namespace Vortos\Http\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Routing\RouteCollection;
use Vortos\Http\Routing\RouteAttributeClassLoader;

class RouteCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if(!$container->getParameter('kernel.enable_routes')){
            return;
        }

        $classLoader = new RouteAttributeClassLoader();
        $controllerIds = $container->findTaggedServiceIds('vortos.api.controller');

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
        return unserialize($serializedRoutes, [
            'allowed_classes' => [
                \Symfony\Component\Routing\RouteCollection::class,
                \Symfony\Component\Routing\Route::class,
                \Symfony\Component\Routing\CompiledRoute::class,
            ],
        ]);
    }
}
