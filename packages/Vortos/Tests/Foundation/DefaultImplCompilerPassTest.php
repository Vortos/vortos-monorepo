<?php

declare(strict_types=1);

namespace Vortos\Tests\Foundation;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Vortos\Foundation\DependencyInjection\Attribute\DefaultImpl;
use Vortos\Foundation\DependencyInjection\Compiler\DefaultImplCompilerPass;

// --- fixtures ---

interface SingleImplInterface {}
interface AnotherInterface {}
interface ThirdInterface {}

#[DefaultImpl]
final class SingleImplClass implements SingleImplInterface {}

#[DefaultImpl(AnotherInterface::class)]
final class MultiImplClass implements SingleImplInterface, AnotherInterface {}

#[DefaultImpl]
final class NoAppInterfaceClass {}

#[DefaultImpl]
final class AmbiguousImplClass implements SingleImplInterface, AnotherInterface {}

// --- tests ---

final class DefaultImplCompilerPassTest extends TestCase
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
        $def->addTag('vortos.default_impl');
        $container->setDefinition($class, $def);
    }

    public function test_creates_alias_for_single_interface(): void
    {
        $container = $this->container();
        $this->register($container, SingleImplClass::class);

        (new DefaultImplCompilerPass())->process($container);

        $this->assertTrue($container->hasAlias(SingleImplInterface::class));
        $this->assertSame(SingleImplClass::class, (string) $container->getAlias(SingleImplInterface::class));
    }

    public function test_creates_alias_for_explicit_interface_on_multi_impl_class(): void
    {
        $container = $this->container();
        $this->register($container, MultiImplClass::class);

        (new DefaultImplCompilerPass())->process($container);

        $this->assertTrue($container->hasAlias(AnotherInterface::class));
        $this->assertSame(MultiImplClass::class, (string) $container->getAlias(AnotherInterface::class));
    }

    public function test_does_not_override_existing_alias(): void
    {
        $container = $this->container();
        $this->register($container, SingleImplClass::class);

        // Explicit alias registered before the pass runs
        $container->setAlias(SingleImplInterface::class, 'some.other.service');

        (new DefaultImplCompilerPass())->process($container);

        $this->assertSame('some.other.service', (string) $container->getAlias(SingleImplInterface::class));
    }

    public function test_does_not_override_existing_definition(): void
    {
        $container = $this->container();
        $this->register($container, SingleImplClass::class);

        // Explicit definition for the interface
        $container->setDefinition(SingleImplInterface::class, new Definition('SomeOtherImpl'));

        (new DefaultImplCompilerPass())->process($container);

        // The definition should remain untouched, not replaced by an alias
        $this->assertFalse($container->hasAlias(SingleImplInterface::class));
    }

    public function test_throws_on_ambiguous_interface_without_explicit_arg(): void
    {
        $container = $this->container();
        $this->register($container, AmbiguousImplClass::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/ambiguous/i');

        (new DefaultImplCompilerPass())->process($container);
    }

    public function test_skips_class_without_default_impl_attribute(): void
    {
        // A class tagged 'vortos.default_impl' but without the PHP attribute is silently skipped.
        // This can happen if the tag is added manually in services.php without the attribute.
        $container = $this->container();
        $def = new Definition(\stdClass::class);
        $def->addTag('vortos.default_impl');
        $container->setDefinition(\stdClass::class, $def);

        (new DefaultImplCompilerPass())->process($container);

        // No alias should be created
        $this->assertFalse($container->hasAlias(\stdClass::class));
    }

    public function test_skips_non_existent_class(): void
    {
        $container = $this->container();
        $def = new Definition('NonExistentClass\\That\\DoesNotExist');
        $def->addTag('vortos.default_impl');
        $container->setDefinition('non_existent', $def);

        // Should not throw
        (new DefaultImplCompilerPass())->process($container);

        $this->assertTrue(true);
    }
}
