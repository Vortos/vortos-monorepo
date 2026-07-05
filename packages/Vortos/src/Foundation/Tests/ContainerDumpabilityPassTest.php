<?php

declare(strict_types=1);

namespace Vortos\Foundation\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Foundation\DependencyInjection\Compiler\ContainerDumpabilityPass;

/**
 * B21: the guard that keeps the prod HTTP container dumpable. Mirrors PhpDumper's own rules — a raw
 * object instance as a service argument is rejected; inline Definitions / References / scalars / enums
 * are fine.
 */
final class ContainerDumpabilityPassTest extends TestCase
{
    public function test_raw_object_argument_is_flagged_with_service_id_and_class(): void
    {
        $container = new ContainerBuilder();
        $container->register('svc', DumpTarget::class)
            ->setArgument(0, new \DateTimeImmutable('@0'));

        try {
            (new ContainerDumpabilityPass())->process($container);
            $this->fail('Expected a LogicException for the raw object argument.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('svc', $e->getMessage());
            $this->assertStringContainsString('DateTimeImmutable', $e->getMessage());
            $this->assertStringContainsString('[0]', $e->getMessage());
        }
    }

    public function test_object_inside_array_argument_is_flagged(): void
    {
        $container = new ContainerBuilder();
        $container->register('svc', DumpTarget::class)
            ->setArgument('$items', [new \stdClass(), 'scalar-ok']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/stdClass/');

        (new ContainerDumpabilityPass())->process($container);
    }

    public function test_object_in_nested_inline_definition_is_flagged(): void
    {
        $container = new ContainerBuilder();
        $inner = new Definition(DumpTarget::class, [new \stdClass()]);
        $container->register('svc', DumpTarget::class)->setArgument(0, $inner);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/inline/');

        (new ContainerDumpabilityPass())->process($container);
    }

    public function test_object_in_method_call_argument_is_flagged(): void
    {
        $container = new ContainerBuilder();
        $container->register('svc', DumpTarget::class)
            ->addMethodCall('setThing', [new \stdClass()]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/setThing\(\)/');

        (new ContainerDumpabilityPass())->process($container);
    }

    public function test_object_factory_is_flagged(): void
    {
        $container = new ContainerBuilder();
        $container->register('svc', DumpTarget::class)->setFactory([new \stdClass(), 'make']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/factory/');

        (new ContainerDumpabilityPass())->process($container);
    }

    public function test_dumpable_arguments_pass_and_really_dump(): void
    {
        $container = new ContainerBuilder();
        $container->register('dep', DumpTarget::class)->setPublic(true);
        $container->register('svc', DumpTarget::class)
            ->setArguments([
                'a-scalar',
                42,
                ['nested', 'array'],
                new Reference('dep'),
                DumpEnum::A,                                   // enum instance — dumpable
                new Definition(DumpTarget::class, ['inline']), // inline definition — dumpable
                new TaggedIteratorArgument('some.tag'),        // DI argument value object
            ])
            ->setPublic(true);

        // The guard must not throw...
        (new ContainerDumpabilityPass())->process($container);

        // ...and the real PhpDumper must actually serialise it.
        $container->compile();
        $php = (new PhpDumper($container))->dump(['class' => 'DumpableTestContainer_' . uniqid()]);
        $this->assertStringContainsString('class DumpableTestContainer_', $php);
    }

    public function test_synthetic_and_abstract_definitions_are_skipped(): void
    {
        $container = new ContainerBuilder();
        $container->register('synthetic', DumpTarget::class)->setSynthetic(true);
        $abstract = $container->register('abstract', DumpTarget::class)->setAbstract(true);
        $abstract->setArgument(0, new \stdClass()); // would be flagged if not skipped

        (new ContainerDumpabilityPass())->process($container);

        $this->addToAssertionCount(1);
    }
}

class DumpTarget
{
    public function setThing(mixed $thing): void {}
}

enum DumpEnum
{
    case A;
    case B;
}
