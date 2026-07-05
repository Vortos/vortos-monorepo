<?php

declare(strict_types=1);

namespace Vortos\Http\Tests\Unit\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouteCollection;
use Vortos\Http\DependencyInjection\Compiler\RouteCompilerPass;
use Vortos\Http\Exception\DuplicateRouteNameException;

/**
 * GAP-C: RouteCompilerPass must fail fast on duplicate route names and compile deterministically.
 *
 * The production bug: two controllers both registered `vortos.health.ready`; the "last addCollection
 * wins" over unstable tagged-service iteration order meant byte-identical images compiled the route
 * to different controllers in different containers. These tests lock the fail-fast + stable-order fix.
 */
final class RouteCompilerPassDuplicateTest extends TestCase
{
    public function test_duplicate_route_name_fails_fast_with_both_controllers_named(): void
    {
        $container = $this->containerWith([
            'app.controller.a' => DuplicateRouteControllerA::class,
            'app.controller.b' => DuplicateRouteControllerB::class,
        ]);

        try {
            (new RouteCompilerPass())->process($container);
            self::fail('expected a DuplicateRouteNameException');
        } catch (DuplicateRouteNameException $e) {
            self::assertSame('dup.route', $e->routeName);
            self::assertContains(DuplicateRouteControllerA::class, [$e->firstController, $e->secondController]);
            self::assertContains(DuplicateRouteControllerB::class, [$e->firstController, $e->secondController]);
            self::assertStringContainsString('dup.route', $e->getMessage());
            self::assertStringContainsString(DuplicateRouteControllerA::class, $e->getMessage());
            self::assertStringContainsString(DuplicateRouteControllerB::class, $e->getMessage());
        }
    }

    public function test_distinct_route_names_compile_cleanly(): void
    {
        $container = $this->containerWith([
            'app.controller.a' => DuplicateRouteControllerA::class,
            'app.controller.c' => DistinctRouteController::class,
        ]);

        (new RouteCompilerPass())->process($container);

        $collection = $this->compiledCollection($container);
        self::assertNotNull($collection->get('dup.route'));
        self::assertNotNull($collection->get('distinct.route'));
        self::assertNotNull($collection->get('other.route'));
    }

    public function test_compilation_is_deterministic_regardless_of_registration_order(): void
    {
        $forward = $this->containerWith([
            'app.controller.a' => DistinctRouteController::class,
            'app.controller.z' => AnotherDistinctController::class,
        ]);
        $reverse = $this->containerWith([
            'app.controller.z' => AnotherDistinctController::class,
            'app.controller.a' => DistinctRouteController::class,
        ]);

        (new RouteCompilerPass())->process($forward);
        (new RouteCompilerPass())->process($reverse);

        self::assertSame(
            array_keys($this->compiledCollection($forward)->all()),
            array_keys($this->compiledCollection($reverse)->all()),
            'route order must be identical no matter the tagged-service registration order',
        );
    }

    /** @param array<string, class-string> $controllers */
    private function containerWith(array $controllers): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.enable_routes', true);

        foreach ($controllers as $id => $class) {
            $def = new Definition($class);
            $def->addTag('vortos.api.controller');
            $container->setDefinition($id, $def);
        }

        return $container;
    }

    private function compiledCollection(ContainerBuilder $container): RouteCollection
    {
        $def = $container->getDefinition(RouteCollection::class);
        /** @var array{0: string} $args */
        $args = $def->getArguments();

        return RouteCompilerPass::createRouteCollection($args[0]);
    }
}

final class DuplicateRouteControllerA
{
    #[Route('/a', name: 'dup.route', methods: ['GET'])]
    public function __invoke(): void {}
}

final class DuplicateRouteControllerB
{
    #[Route('/b', name: 'dup.route', methods: ['GET'])]
    public function __invoke(): void {}
}

final class DistinctRouteController
{
    #[Route('/distinct', name: 'distinct.route', methods: ['GET'])]
    public function __invoke(): void {}

    #[Route('/other', name: 'other.route', methods: ['GET'])]
    public function other(): void {}
}

final class AnotherDistinctController
{
    #[Route('/z', name: 'z.route', methods: ['GET'])]
    public function __invoke(): void {}
}
