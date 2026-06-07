<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\ObjectStore\Attribute\AsObjectStoreMiddleware;
use Vortos\ObjectStore\DependencyInjection\Compiler\MiddlewareCompilerPass;
use Vortos\ObjectStore\Middleware\HookMiddleware;
use Vortos\ObjectStore\Middleware\ObjectStoreMiddlewareStack;

final class MiddlewareCompilerPassTest extends TestCase
{
    public function test_wires_middlewares_by_priority(): void
    {
        $container = new ContainerBuilder();
        $container->register(ObjectStoreMiddlewareStack::class, ObjectStoreMiddlewareStack::class)
            ->setArgument('$middlewares', []);
        $container->register('low', LowPriorityObjectStoreMiddleware::class)
            ->addTag('vortos_object_store.middleware');
        $container->register('high', HighPriorityObjectStoreMiddleware::class)
            ->addTag('vortos_object_store.middleware');

        (new MiddlewareCompilerPass())->process($container);

        $middlewares = $container->getDefinition(ObjectStoreMiddlewareStack::class)->getArgument('$middlewares');

        $this->assertContainsOnlyInstancesOf(Reference::class, $middlewares);
        $this->assertSame('high', (string) $middlewares[0]);
        $this->assertSame('low', (string) $middlewares[1]);
    }

    public function test_wires_observers_into_hook_middleware(): void
    {
        $container = new ContainerBuilder();
        $container->register(ObjectStoreMiddlewareStack::class, ObjectStoreMiddlewareStack::class)
            ->setArgument('$middlewares', []);
        $container->register(HookMiddleware::class, HookMiddleware::class)
            ->setArgument('$observers', []);
        $container->register('observer.one', ObjectStoreObserverFixture::class)
            ->addTag('vortos_object_store.observer');

        (new MiddlewareCompilerPass())->process($container);

        $observers = $container->getDefinition(HookMiddleware::class)->getArgument('$observers');

        $this->assertSame('observer.one', (string) $observers[0]);
    }
}

#[AsObjectStoreMiddleware(priority: 10)]
final class LowPriorityObjectStoreMiddleware
{
}

#[AsObjectStoreMiddleware(priority: 100)]
final class HighPriorityObjectStoreMiddleware
{
}

final class ObjectStoreObserverFixture
{
}
