<?php

declare(strict_types=1);

namespace Vortos\Foundation\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Vortos\Foundation\DependencyInjection\Attribute\OverrideImpl;
use Vortos\Foundation\DependencyInjection\Compiler\OverrideImplCompilerPass;

// --- fixtures ---

interface OverridableInterface {}
interface AnotherOverridableInterface {}

#[OverrideImpl]
final class OverrideImplClass implements OverridableInterface {}

#[OverrideImpl]
final class SecondOverrideImplClass implements OverridableInterface {}

#[OverrideImpl(AnotherOverridableInterface::class)]
final class ExplicitOverrideImplClass implements OverridableInterface, AnotherOverridableInterface {}

#[OverrideImpl(AnotherOverridableInterface::class)]
final class WrongExplicitOverrideClass implements OverridableInterface {}

#[OverrideImpl]
final class AmbiguousOverrideClass implements OverridableInterface, AnotherOverridableInterface {}

#[OverrideImpl]
final class NoInterfaceOverrideClass {}

// --- tests ---

final class OverrideImplCompilerPassTest extends TestCase
{
    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());
        return $container;
    }

    private function register(ContainerBuilder $container, string $class): void
    {
        $def = new Definition($class);
        $def->addTag('vortos.override_impl');
        $container->setDefinition($class, $def);
    }

    public function test_creates_alias_when_no_existing_alias(): void
    {
        $container = $this->container();
        $this->register($container, OverrideImplClass::class);

        (new OverrideImplCompilerPass())->process($container);

        $this->assertTrue($container->hasAlias(OverridableInterface::class));
        $this->assertSame(OverrideImplClass::class, (string) $container->getAlias(OverridableInterface::class));
    }

    public function test_replaces_existing_alias(): void
    {
        $container = $this->container();
        $this->register($container, OverrideImplClass::class);
        $container->setAlias(OverridableInterface::class, 'some.other.service');

        (new OverrideImplCompilerPass())->process($container);

        $this->assertSame(OverrideImplClass::class, (string) $container->getAlias(OverridableInterface::class));
    }

    public function test_replaces_existing_definition(): void
    {
        $container = $this->container();
        $this->register($container, OverrideImplClass::class);
        $container->setDefinition(OverridableInterface::class, new Definition('SomeOtherImpl'));

        (new OverrideImplCompilerPass())->process($container);

        $this->assertTrue($container->hasAlias(OverridableInterface::class));
        $this->assertSame(OverrideImplClass::class, (string) $container->getAlias(OverridableInterface::class));
    }

    public function test_explicit_interface_arg_used(): void
    {
        $container = $this->container();
        $this->register($container, ExplicitOverrideImplClass::class);

        (new OverrideImplCompilerPass())->process($container);

        $this->assertTrue($container->hasAlias(AnotherOverridableInterface::class));
        $this->assertSame(ExplicitOverrideImplClass::class, (string) $container->getAlias(AnotherOverridableInterface::class));
    }

    public function test_throws_on_ambiguous_interface(): void
    {
        $container = $this->container();
        $this->register($container, AmbiguousOverrideClass::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/ambiguous/i');

        (new OverrideImplCompilerPass())->process($container);
    }

    public function test_throws_on_class_not_implementing_explicit_interface(): void
    {
        $container = $this->container();
        $this->register($container, WrongExplicitOverrideClass::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote(AnotherOverridableInterface::class, '/') . '/');

        (new OverrideImplCompilerPass())->process($container);
    }

    public function test_throws_on_duplicate_override_same_interface(): void
    {
        $container = $this->container();
        $this->register($container, OverrideImplClass::class);
        $this->register($container, SecondOverrideImplClass::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/conflict/i');

        (new OverrideImplCompilerPass())->process($container);
    }

    public function test_all_errors_collected_before_throwing(): void
    {
        $container = $this->container();
        $this->register($container, AmbiguousOverrideClass::class);
        $this->register($container, NoInterfaceOverrideClass::class);

        try {
            (new OverrideImplCompilerPass())->process($container);
            $this->fail('Expected LogicException was not thrown.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('2 container configuration error(s)', $e->getMessage());
            $this->assertStringContainsString(AmbiguousOverrideClass::class, $e->getMessage());
            $this->assertStringContainsString(NoInterfaceOverrideClass::class, $e->getMessage());
        }
    }

    public function test_skips_class_without_attribute(): void
    {
        $container = $this->container();
        $def = new Definition(\stdClass::class);
        $def->addTag('vortos.override_impl');
        $container->setDefinition(\stdClass::class, $def);

        (new OverrideImplCompilerPass())->process($container);

        $this->assertFalse($container->hasAlias(\stdClass::class));
    }

    public function test_sets_override_impl_bindings_parameter(): void
    {
        $container = $this->container();
        $this->register($container, OverrideImplClass::class);

        (new OverrideImplCompilerPass())->process($container);

        $bindings = $container->getParameter('vortos.override_impl.bindings');

        $this->assertIsArray($bindings);
        $this->assertArrayHasKey(OverridableInterface::class, $bindings);
        $this->assertSame(OverrideImplClass::class, $bindings[OverridableInterface::class]['class']);
    }
}
