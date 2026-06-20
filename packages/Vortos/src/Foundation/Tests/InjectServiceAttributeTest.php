<?php

declare(strict_types=1);

namespace Vortos\Foundation\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Foundation\DependencyInjection\Attribute\InjectService;

// --- fixtures ---

interface IsaStoreInterface {}

final class IsaStoreA implements IsaStoreInterface {}
final class IsaStoreB implements IsaStoreInterface {}

final class IsaConsumer
{
    public function __construct(
        #[InjectService('isa.store.b')]
        public readonly IsaStoreInterface $store,
    ) {}
}

// --- tests ---

final class InjectServiceAttributeTest extends TestCase
{
    public function test_attribute_is_target_parameter(): void
    {
        $reflection = new \ReflectionClass(InjectService::class);
        $attrs      = $reflection->getAttributes(\Attribute::class);

        $this->assertNotEmpty($attrs);

        $flags = $attrs[0]->newInstance()->flags;
        $this->assertSame(\Attribute::TARGET_PARAMETER, $flags);
    }

    public function test_stores_service_id_as_reference(): void
    {
        $attr = new InjectService('my.service');

        $this->assertInstanceOf(Reference::class, $attr->value);
        $this->assertSame('my.service', (string) $attr->value);
    }

    public function test_extends_autowire(): void
    {
        $this->assertInstanceOf(Autowire::class, new InjectService('some.service'));
    }

    public function test_different_service_ids_produce_different_references(): void
    {
        $a = new InjectService('service.a');
        $b = new InjectService('service.b');

        $this->assertSame('service.a', (string) $a->value);
        $this->assertSame('service.b', (string) $b->value);
        $this->assertNotSame((string) $a->value, (string) $b->value);
    }

    public function test_autowire_compatibility_in_container(): void
    {
        $container = new ContainerBuilder();

        $container->register('isa.store.a', IsaStoreA::class)->setPublic(true);
        $container->register('isa.store.b', IsaStoreB::class)->setPublic(true);

        $container->register(IsaConsumer::class, IsaConsumer::class)
            ->setAutowired(true)
            ->setPublic(true);

        $container->compile();

        /** @var IsaConsumer $consumer */
        $consumer = $container->get(IsaConsumer::class);

        $this->assertInstanceOf(IsaStoreB::class, $consumer->store);
    }
}
