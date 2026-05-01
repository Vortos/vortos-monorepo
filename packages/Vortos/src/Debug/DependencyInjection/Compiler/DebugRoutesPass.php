<?php

declare(strict_types=1);

namespace Vortos\Debug\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Debug\Command\DebugRoutesCommand;
use Vortos\Http\Routing\RouteAttributeClassLoader;

final class DebugRoutesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $controllerIds = $container->findTaggedServiceIds('vortos.api.controller');

        if (empty($controllerIds)) {
            $container->setParameter('vortos.debug.routes', []);
            return;
        }

        $loader = new RouteAttributeClassLoader();
        $routes = [];

        foreach ($controllerIds as $id => $tags) {
            $class = $container->getDefinition($id)->getClass();

            if (!$class || !class_exists($class)) {
                continue;
            }

            $collection = $loader->load($class);

            foreach ($collection->all() as $name => $route) {
                $routes[] = [
                    'name'       => $name,
                    'path'       => $route->getPath(),
                    'methods'    => $route->getMethods() ?: ['ANY'],
                    'controller' => $route->getDefault('_controller'),
                ];
            }
        }

        usort($routes, fn($a, $b) => strcmp($a['path'], $b['path']));

        $container->findDefinition(DebugRoutesCommand::class)
            ->setArgument('$routes', $routes);
    }
}
