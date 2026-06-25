<?php

declare(strict_types=1);

namespace Vortos\Auth\Scim\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Auth\Scim\Http\ScimController;
use Vortos\Auth\Scim\Http\ScimDiscoveryController;
use Vortos\Auth\Scim\Middleware\ScimAuthMiddleware;

/**
 * Builds the SCIM route map at compile time for ScimAuthMiddleware.
 *
 * Maps each ScimController method to its resource type so the middleware
 * can derive the required scope (scim:{resource}:{read|write}).
 */
final class ScimCompilerPass implements CompilerPassInterface
{
    private const USER_METHODS = [
        'createUser', 'getUser', 'listUsers', 'replaceUser', 'patchUser', 'deleteUser',
    ];

    private const GROUP_METHODS = [
        'createGroup', 'getGroup', 'listGroups', 'replaceGroup', 'patchGroup', 'deleteGroup',
    ];

    private const DISCOVERY_METHODS = [
        'serviceProviderConfig', 'resourceTypes', 'schemas',
    ];

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(ScimAuthMiddleware::class)) {
            return;
        }

        $routeMap = [];

        if ($container->hasDefinition(ScimController::class)) {
            $class = ScimController::class;

            foreach (self::USER_METHODS as $method) {
                $routeMap[$class . '::' . $method] = ['resource' => 'users'];
            }

            foreach (self::GROUP_METHODS as $method) {
                $routeMap[$class . '::' . $method] = ['resource' => 'groups'];
            }
        }

        if ($container->hasDefinition(ScimDiscoveryController::class)) {
            $class = ScimDiscoveryController::class;

            foreach (self::DISCOVERY_METHODS as $method) {
                $routeMap[$class . '::' . $method] = ['resource' => 'discovery'];
            }

            $routeMap[$class] = ['resource' => 'discovery'];
        }

        $container->getDefinition(ScimAuthMiddleware::class)
            ->setArgument('$routeMap', $routeMap);
    }
}
