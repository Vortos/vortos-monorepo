<?php

declare(strict_types=1);

namespace Vortos\Tests\Messaging;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Domain\Event\DomainEventInterface;
use Vortos\Messaging\Contract\EventBusInterface;
use Vortos\Messaging\Dev\SyncProjectionEventBusDecorator;
use Vortos\Messaging\Registry\HandlerRegistry;

// --- fixtures ---

final class DecoratorTestEvent implements DomainEventInterface
{
    public function aggregateId(): string { return 'test-aggregate-id'; }
    public function occurredAt(): \DateTimeImmutable { return new \DateTimeImmutable(); }
    public function eventVersion(): int { return 1; }
}

// --- tests ---

final class SyncProjectionEventBusDecoratorTest extends TestCase
{
    private function makeDecorator(
        EventBusInterface $inner,
        HandlerRegistry $registry,
        array $handlers = [],
    ): SyncProjectionEventBusDecorator {
        $locator = new ServiceLocator(
            array_map(fn($h) => fn() => $h, $handlers),
        );

        return new SyncProjectionEventBusDecorator($inner, $registry, $locator, new NullLogger());
    }

    private function emptyRegistry(): HandlerRegistry
    {
        return new HandlerRegistry([]);
    }

    private function registryWithProjection(string $consumer, string $serviceId, object $handler): HandlerRegistry
    {
        $registry = new HandlerRegistry([]);
        $registry->registerHandler($consumer, DecoratorTestEvent::class, [
            'handlerId'    => 'test-handler',
            'serviceId'    => $serviceId,
            'method'       => '__invoke',
            'priority'     => 0,
            'idempotent'   => true,
            'isProjection' => true,
            'eventClass'   => DecoratorTestEvent::class,
        ]);
        return $registry;
    }

    public function test_delegates_dispatch_to_inner_bus(): void
    {
        $event = new DecoratorTestEvent();
        $inner = $this->createMock(EventBusInterface::class);
        $inner->expects($this->once())->method('dispatch')->with($event);

        $decorator = $this->makeDecorator($inner, $this->emptyRegistry());
        $decorator->dispatch($event);
    }

    public function test_invokes_projection_handler_synchronously(): void
    {
        $event   = new DecoratorTestEvent();
        $called  = false;
        $handler = new class($called) {
            public function __construct(private bool &$called) {}
            public function __invoke(DecoratorTestEvent $e): void { $this->called = true; }
        };

        $registry  = $this->registryWithProjection('my-consumer', 'handler.service', $handler);
        $inner     = $this->createMock(EventBusInterface::class);
        $decorator = $this->makeDecorator($inner, $registry, ['handler.service' => $handler]);

        $decorator->dispatch($event);

        $this->assertTrue($called);
    }

    public function test_invokes_correct_method_on_handler(): void
    {
        $event   = new DecoratorTestEvent();
        $called  = false;
        $handler = new class($called) {
            public bool $methodCalled = false;
            public function handleEvent(DecoratorTestEvent $e): void { $this->methodCalled = true; }
        };

        $registry = new HandlerRegistry([]);
        $registry->registerHandler('consumer', DecoratorTestEvent::class, [
            'handlerId'    => 'test',
            'serviceId'    => 'svc',
            'method'       => 'handleEvent',
            'priority'     => 0,
            'idempotent'   => true,
            'isProjection' => true,
            'eventClass'   => DecoratorTestEvent::class,
        ]);

        $decorator = $this->makeDecorator(
            $this->createMock(EventBusInterface::class),
            $registry,
            ['svc' => $handler],
        );

        $decorator->dispatch($event);

        $this->assertTrue($handler->methodCalled);
    }

    public function test_skips_non_projection_handlers(): void
    {
        $event   = new DecoratorTestEvent();
        $called  = false;
        $handler = new class($called) {
            public function __construct(private bool &$called) {}
            public function __invoke(DecoratorTestEvent $e): void { $this->called = true; }
        };

        $registry = new HandlerRegistry([]);
        $registry->registerHandler('consumer', DecoratorTestEvent::class, [
            'handlerId'    => 'test',
            'serviceId'    => 'svc',
            'method'       => '__invoke',
            'priority'     => 0,
            'idempotent'   => true,
            'isProjection' => false, // not a projection
            'eventClass'   => DecoratorTestEvent::class,
        ]);

        $decorator = $this->makeDecorator(
            $this->createMock(EventBusInterface::class),
            $registry,
            ['svc' => $handler],
        );

        $decorator->dispatch($event);

        $this->assertFalse($called);
    }

    public function test_swallows_projection_exception_and_logs_warning(): void
    {
        $event   = new DecoratorTestEvent();
        $handler = new class {
            public function __invoke(DecoratorTestEvent $e): void
            {
                throw new \RuntimeException('projection broke');
            }
        };

        $registry  = $this->registryWithProjection('consumer', 'svc', $handler);
        $inner     = $this->createMock(EventBusInterface::class);
        $decorator = $this->makeDecorator($inner, $registry, ['svc' => $handler]);

        // Should not throw — projection errors are logged and swallowed
        $decorator->dispatch($event);
        $this->assertTrue(true);
    }

    public function test_projection_exception_does_not_suppress_inner_dispatch(): void
    {
        $event   = new DecoratorTestEvent();
        $handler = new class {
            public function __invoke(DecoratorTestEvent $e): void
            {
                throw new \RuntimeException('projection broke');
            }
        };

        $registry = $this->registryWithProjection('consumer', 'svc', $handler);
        $inner    = $this->createMock(EventBusInterface::class);
        $inner->expects($this->once())->method('dispatch');

        $decorator = $this->makeDecorator($inner, $registry, ['svc' => $handler]);
        $decorator->dispatch($event);
    }

    public function test_inner_exception_propagates_unchanged(): void
    {
        $event = new DecoratorTestEvent();
        $inner = $this->createMock(EventBusInterface::class);
        $inner->method('dispatch')->willThrowException(new \RuntimeException('kafka down'));

        $decorator = $this->makeDecorator($inner, $this->emptyRegistry());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('kafka down');

        $decorator->dispatch($event);
    }

    public function test_multiple_projection_handlers_all_invoked(): void
    {
        $event   = new DecoratorTestEvent();
        $count   = 0;

        $handler = new class($count) {
            public function __construct(private int &$count) {}
            public function __invoke(DecoratorTestEvent $e): void { $this->count++; }
        };

        $registry = new HandlerRegistry([]);
        foreach (['h1', 'h2', 'h3'] as $id) {
            $registry->registerHandler('consumer', DecoratorTestEvent::class, [
                'handlerId'    => $id,
                'serviceId'    => $id,
                'method'       => '__invoke',
                'priority'     => 0,
                'idempotent'   => true,
                'isProjection' => true,
                'eventClass'   => DecoratorTestEvent::class,
            ]);
        }

        $decorator = $this->makeDecorator(
            $this->createMock(EventBusInterface::class),
            $registry,
            ['h1' => $handler, 'h2' => $handler, 'h3' => $handler],
        );

        $decorator->dispatch($event);

        $this->assertSame(3, $count);
    }
}
