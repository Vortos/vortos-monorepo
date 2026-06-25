<?php

declare(strict_types=1);

namespace Vortos\Auth\Scim\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Vortos\Auth\Scim\Compiler\ScimCompilerPass;
use Vortos\Auth\Scim\Http\ScimController;
use Vortos\Auth\Scim\Http\ScimDiscoveryController;
use Vortos\Auth\Scim\Middleware\ScimAuthMiddleware;

final class ScimCompilerPassTest extends TestCase
{
    public function test_builds_route_map_for_scim_controller(): void
    {
        $container = new ContainerBuilder();

        $mwDef = new Definition(ScimAuthMiddleware::class);
        $mwDef->setArguments([null, null, []]);
        $container->setDefinition(ScimAuthMiddleware::class, $mwDef);

        $ctrlDef = new Definition(ScimController::class);
        $container->setDefinition(ScimController::class, $ctrlDef);

        $discDef = new Definition(ScimDiscoveryController::class);
        $container->setDefinition(ScimDiscoveryController::class, $discDef);

        (new ScimCompilerPass())->process($container);

        $routeMap = $container->getDefinition(ScimAuthMiddleware::class)->getArgument('$routeMap');

        $this->assertSame(['resource' => 'users'], $routeMap[ScimController::class . '::createUser']);
        $this->assertSame(['resource' => 'users'], $routeMap[ScimController::class . '::getUser']);
        $this->assertSame(['resource' => 'users'], $routeMap[ScimController::class . '::listUsers']);
        $this->assertSame(['resource' => 'users'], $routeMap[ScimController::class . '::replaceUser']);
        $this->assertSame(['resource' => 'users'], $routeMap[ScimController::class . '::patchUser']);
        $this->assertSame(['resource' => 'users'], $routeMap[ScimController::class . '::deleteUser']);

        $this->assertSame(['resource' => 'groups'], $routeMap[ScimController::class . '::createGroup']);
        $this->assertSame(['resource' => 'groups'], $routeMap[ScimController::class . '::getGroup']);
        $this->assertSame(['resource' => 'groups'], $routeMap[ScimController::class . '::listGroups']);

        $this->assertSame(['resource' => 'discovery'], $routeMap[ScimDiscoveryController::class . '::serviceProviderConfig']);
        $this->assertSame(['resource' => 'discovery'], $routeMap[ScimDiscoveryController::class . '::resourceTypes']);
        $this->assertSame(['resource' => 'discovery'], $routeMap[ScimDiscoveryController::class . '::schemas']);
    }

    public function test_noop_when_middleware_not_registered(): void
    {
        $container = new ContainerBuilder();
        (new ScimCompilerPass())->process($container);

        $this->assertFalse($container->hasDefinition(ScimAuthMiddleware::class));
    }

    public function test_handles_missing_controllers_gracefully(): void
    {
        $container = new ContainerBuilder();

        $mwDef = new Definition(ScimAuthMiddleware::class);
        $mwDef->setArguments([null, null, []]);
        $container->setDefinition(ScimAuthMiddleware::class, $mwDef);

        (new ScimCompilerPass())->process($container);

        $routeMap = $container->getDefinition(ScimAuthMiddleware::class)->getArgument('$routeMap');
        $this->assertSame([], $routeMap);
    }
}
