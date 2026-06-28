<?php

declare(strict_types=1);

namespace Vortos\Foundation\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Vortos\Foundation\DependencyInjection\Attribute\AsDecorator;
use Vortos\Foundation\DependencyInjection\Compiler\DecoratorCompilerPass;

// --- fixtures ---

interface DecorableInterface {}
interface UnrelatedInterface {}

class ConcreteService implements DecorableInterface {}

#[AsDecorator(decorates: DecorableInterface::class)]
final class SingleDecorator implements DecorableInterface
{
    public function __construct(public readonly DecorableInterface $inner) {}
}

#[AsDecorator(decorates: DecorableInterface::class, priority: 10)]
final class OuterDecorator implements DecorableInterface
{
    public function __construct(public readonly DecorableInterface $inner) {}
}

#[AsDecorator(decorates: DecorableInterface::class, priority: 5)]
final class InnerDecorator implements DecorableInterface
{
    public function __construct(public readonly DecorableInterface $inner) {}
}

#[AsDecorator(decorates: ConcreteService::class)]
final class ConcreteDecorator
{
    public function __construct(public readonly ConcreteService $inner) {}
}

#[AsDecorator(decorates: SelfReferencingDecorator::class)]
final class SelfReferencingDecorator
{
    public function __construct(public readonly SelfReferencingDecorator $inner) {}
}

#[AsDecorator(decorates: DecorableInterface::class)]
final class MissingInnerParamDecorator implements DecorableInterface
{
    public function __construct() {}
}

#[AsDecorator(decorates: DecorableInterface::class)]
final class WrongInterfaceDecorator implements UnrelatedInterface
{
    public function __construct(public readonly DecorableInterface $inner) {}
}

#[AsDecorator(decorates: DecorableInterface::class, priority: 0)]
final class PriorityConflictDecoratorA implements DecorableInterface
{
    public function __construct(public readonly DecorableInterface $inner) {}
}

#[AsDecorator(decorates: DecorableInterface::class, priority: 0)]
final class PriorityConflictDecoratorB implements DecorableInterface
{
    public function __construct(public readonly DecorableInterface $inner) {}
}

// --- tests ---

final class DecoratorCompilerPassTest extends TestCase
{
    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());
        return $container;
    }

    private function registerConcrete(ContainerBuilder $container): void
    {
        $container->setDefinition(ConcreteService::class, new Definition(ConcreteService::class));
        $container->setAlias(DecorableInterface::class, ConcreteService::class)->setPublic(true);
    }

    private function registerDecorator(ContainerBuilder $container, string $class): void
    {
        $def = new Definition($class);
        $def->addTag('vortos.decorator');
        $container->setDefinition($class, $def);
    }

    public function test_single_decorator_wraps_existing_alias(): void
    {
        $container = $this->container();
        $this->registerConcrete($container);
        $this->registerDecorator($container, SingleDecorator::class);

        (new DecoratorCompilerPass())->process($container);

        $this->assertSame(SingleDecorator::class, (string) $container->getAlias(DecorableInterface::class));
        $this->assertTrue($container->hasAlias(DecorableInterface::class . '.vortos_inner_0'));
        $this->assertSame(
            ConcreteService::class,
            (string) $container->getAlias(DecorableInterface::class . '.vortos_inner_0'),
        );

        $args = $container->getDefinition(SingleDecorator::class)->getArguments();
        $this->assertArrayHasKey('$inner', $args);
        $this->assertSame(
            DecorableInterface::class . '.vortos_inner_0',
            (string) $args['$inner'],
        );
    }

    public function test_decorator_of_concrete_class(): void
    {
        $container = $this->container();
        $container->setDefinition(ConcreteService::class, new Definition(ConcreteService::class));
        $this->registerDecorator($container, ConcreteDecorator::class);

        (new DecoratorCompilerPass())->process($container);

        $this->assertSame(ConcreteDecorator::class, (string) $container->getAlias(ConcreteService::class));
        $this->assertTrue($container->hasAlias(ConcreteService::class . '.vortos_inner_0'));
        $this->assertSame(
            ConcreteService::class,
            (string) $container->getAlias(ConcreteService::class . '.vortos_inner_0'),
        );
    }

    public function test_chain_order_respects_priority(): void
    {
        $container = $this->container();
        $this->registerConcrete($container);
        $this->registerDecorator($container, OuterDecorator::class);
        $this->registerDecorator($container, InnerDecorator::class);

        (new DecoratorCompilerPass())->process($container);

        // Outermost alias points to the highest-priority decorator
        $this->assertSame(OuterDecorator::class, (string) $container->getAlias(DecorableInterface::class));

        // Step 0: priority 5 (InnerDecorator) processed first — inner_0 wraps ConcreteService
        $this->assertSame(
            ConcreteService::class,
            (string) $container->getAlias(DecorableInterface::class . '.vortos_inner_0'),
        );

        // Step 1: priority 10 (OuterDecorator) processed second — inner_1 wraps InnerDecorator
        $this->assertSame(
            InnerDecorator::class,
            (string) $container->getAlias(DecorableInterface::class . '.vortos_inner_1'),
        );
    }

    public function test_inner_alias_points_to_original_service(): void
    {
        $container = $this->container();
        $this->registerConcrete($container);
        $this->registerDecorator($container, SingleDecorator::class);

        (new DecoratorCompilerPass())->process($container);

        $innerAlias = DecorableInterface::class . '.vortos_inner_0';
        $this->assertTrue($container->hasAlias($innerAlias));
        $this->assertSame(ConcreteService::class, (string) $container->getAlias($innerAlias));
        $this->assertFalse($container->getAlias($innerAlias)->isPublic());
    }

    public function test_throws_when_target_does_not_exist(): void
    {
        $container = $this->container();

        $def = new Definition(SingleDecorator::class);
        $def->addTag('vortos.decorator');
        $container->setDefinition(SingleDecorator::class, $def);

        // DecorableInterface has no alias or definition — target does not exist

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/no alias or definition/i');

        (new DecoratorCompilerPass())->process($container);
    }

    public function test_throws_when_decorator_does_not_implement_interface(): void
    {
        $container = $this->container();
        $this->registerConcrete($container);
        $this->registerDecorator($container, WrongInterfaceDecorator::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/does not implement/i');

        (new DecoratorCompilerPass())->process($container);
    }

    public function test_throws_when_no_inner_param(): void
    {
        $container = $this->container();
        $this->registerConcrete($container);
        $this->registerDecorator($container, MissingInnerParamDecorator::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/No constructor parameter/i');

        (new DecoratorCompilerPass())->process($container);
    }

    public function test_throws_on_self_decoration(): void
    {
        $container = $this->container();

        // className === decorates — the class decorates its own FQCN
        $def = new Definition(SelfReferencingDecorator::class);
        $def->addTag('vortos.decorator');
        $container->setDefinition(SelfReferencingDecorator::class, $def);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/cannot decorate itself/i');

        (new DecoratorCompilerPass())->process($container);
    }

    public function test_throws_on_priority_conflict(): void
    {
        $container = $this->container();
        $this->registerConcrete($container);
        $this->registerDecorator($container, PriorityConflictDecoratorA::class);
        $this->registerDecorator($container, PriorityConflictDecoratorB::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Priority conflict/i');

        (new DecoratorCompilerPass())->process($container);
    }

    public function test_all_errors_collected_before_throwing(): void
    {
        $container = $this->container();
        $this->registerConcrete($container);
        $this->registerDecorator($container, MissingInnerParamDecorator::class);
        $this->registerDecorator($container, WrongInterfaceDecorator::class);

        try {
            (new DecoratorCompilerPass())->process($container);
            $this->fail('Expected LogicException was not thrown.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('2 container configuration error(s)', $e->getMessage());
            $this->assertStringContainsString(MissingInnerParamDecorator::class, $e->getMessage());
            $this->assertStringContainsString(WrongInterfaceDecorator::class, $e->getMessage());
        }
    }

    public function test_skips_class_without_attribute(): void
    {
        $container = $this->container();
        $this->registerConcrete($container);

        $def = new Definition(\stdClass::class);
        $def->addTag('vortos.decorator');
        $container->setDefinition(\stdClass::class, $def);

        (new DecoratorCompilerPass())->process($container);

        // No decoration applied — outer alias still points to the original concrete
        $this->assertSame(ConcreteService::class, (string) $container->getAlias(DecorableInterface::class));
    }
}
