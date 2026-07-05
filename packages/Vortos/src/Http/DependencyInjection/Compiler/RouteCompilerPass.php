<?php

declare(strict_types=1);

namespace Vortos\Http\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Routing\RouteCollection;
use Vortos\Http\Exception\DuplicateRouteNameException;
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

        // Deterministic order: findTaggedServiceIds() iteration order is NOT stable across
        // compilations (it mirrors definition-registration order, which varies by extension load
        // order and container). A byte-identical image could therefore compile the same route name
        // to different controllers in different containers. Sorting by service id makes the compiled
        // RouteCollection reproducible.
        ksort($controllerIds);

        $routes = new RouteCollection();

        // Claimed route names → the controller class that first defined each. A second controller
        // claiming the same route name is a hard, fail-fast compile error: silently letting the
        // "last addCollection wins" is exactly how two packages both registering /health/ready
        // produced a non-deterministic route in production (GAP-C). A duplicate route name is a bug,
        // never a feature.
        $claimedBy = [];

        foreach ($controllerIds as $id => $tags) {
            $class = $container->getDefinition($id)->getClass();
            if ($class === null) {
                continue;
            }

            $controllerRoutes = $classLoader->load($class);

            foreach ($controllerRoutes->all() as $routeName => $route) {
                if (isset($claimedBy[$routeName])) {
                    throw new DuplicateRouteNameException($routeName, $claimedBy[$routeName], $class);
                }
                $claimedBy[$routeName] = $class;
            }

            $routes->addCollection($controllerRoutes);
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
