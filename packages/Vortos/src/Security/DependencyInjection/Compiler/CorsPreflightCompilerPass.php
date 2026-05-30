<?php

declare(strict_types=1);

namespace Vortos\Security\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Routing\CompiledRoute;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Vortos\Security\Cors\Controller\CorsPreflightController;
use Vortos\Security\Cors\Middleware\CorsMiddleware;

/**
 * Injects a companion OPTIONS route for every non-OPTIONS route at compile time.
 *
 * This solves the structural problem where the router rejects OPTIONS preflights
 * with 405 before the middleware pipeline (and CorsMiddleware) can run.
 *
 * For each route with explicit HTTP methods that does not already include OPTIONS,
 * this pass adds a sibling route on the same path that:
 *   - accepts only OPTIONS
 *   - points to CorsPreflightController (which is never actually invoked)
 *   - carries _cors_owner so CorsMiddleware can resolve per-route #[Cors] overrides
 *
 * Must run after RouteCompilerPass (priority 80), registered at priority 70.
 */
final class CorsPreflightCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(RouteCollection::class)) {
            return;
        }

        if (!$container->hasDefinition(CorsMiddleware::class)) {
            return;
        }

        if (!$container->hasDefinition(CorsPreflightController::class)) {
            $container->register(CorsPreflightController::class, CorsPreflightController::class)
                ->setPublic(true)
                ->setShared(true);
        }

        $definition = $container->getDefinition(RouteCollection::class);
        $arguments  = $definition->getArguments();

        if (empty($arguments) || !isset($arguments[0])) {
            return;
        }

        /** @var RouteCollection $routes */
        $routes = unserialize($definition->getArgument(0), [
            'allowed_classes' => [
                RouteCollection::class,
                Route::class,
                CompiledRoute::class,
            ],
        ]);

        $preflightRoutes = new RouteCollection();

        foreach ($routes->all() as $name => $route) {
            $methods = $route->getMethods();

            if (empty($methods) || in_array('OPTIONS', $methods, true)) {
                continue;
            }

            $originalController = explode('::', $route->getDefault('_controller') ?? '', 2)[0];

            if ($originalController === '') {
                continue;
            }

            $optionsRoute = new Route(
                path: $route->getPath(),
                defaults: [
                    '_controller' => CorsPreflightController::class . '::__invoke',
                    '_cors_owner' => $originalController,
                ],
                requirements: $route->getRequirements(),
                options:      $route->getOptions(),
                host:         $route->getHost(),
                methods:      ['OPTIONS'],
                schemes:      $route->getSchemes(),
                condition:    $route->getCondition(),
            );

            $preflightRoutes->add($name . '.__options_preflight', $optionsRoute);
        }

        $routes->addCollection($preflightRoutes);

        $definition->setArgument(0, serialize($routes));
    }
}
