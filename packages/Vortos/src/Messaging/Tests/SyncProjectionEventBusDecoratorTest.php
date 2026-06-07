<?php

declare(strict_types=1);

namespace Vortos\Messaging\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Domain\Event\EventEnvelope;
use Vortos\Domain\Event\Metadata;
use Vortos\Messaging\Contract\EventBusInterface;
use Vortos\Messaging\Dev\SyncProjectionEventBusDecorator;
use Vortos\Messaging\Registry\HandlerRegistry;
use Psr\Log\AbstractLogger;

// Pure POPO event payload
final readonly class DecoratorTestEvent
{
    public function __construct(
        public string $id = 'test-id',
    ) {}
}

final class SyncProjectionEventBusDecoratorTest extends TestCase
{
    private function envelope(object $payload): EventEnvelope
    {
        return new EventEnvelope(
            eventId:          'evt-00000000-0000-0000-0000-000000000001',
            aggregateId:      'agg-001',
            aggregateType:    'TestAggregate',
            aggregateVersion: 1,
            payloadType:      $payload::class,
            schemaVersion:    1,
            occurredAt:       new \DateTimeImmutable('2026-01-01T00:00:00Z'),
            payload:          $payload,
            metadata:         Metadata::empty(),
        );
    }

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

    private function registryWithProjection(string $consumer, string $serviceId): HandlerRegistry
    {
        $registry = new HandlerRegistry([]);
        $registry->registerHandler($consumer, DecoratorTestEvent::class, [
            'handlerId'    => 'test-handler',
            'serviceId'    => $serviceId,
            'method'       => '__invoke',
            'priority'     => 0,
            'idempotent'   => true,
            'isProjection' => true,
        ]);
        return $registry;
    }

    public function test_delegates_dispatch_to_inner_bus(): void
    {
        $envelope = $this->envelope(new DecoratorTestEvent());
        $inner    = $this->createMock(EventBusInterface::class);
        $inner->expects($this->once())->method('dispatch')->with($envelope);

        $this->makeDecorator($inner, $this->emptyRegistry())->dispatch($envelope);
    }

    public function test_invokes_projection_handler_synchronously(): void
    {
        $called  = false;
        $handler = new class($called) {
            public function __construct(private bool &$called) {}
            public function __invoke(DecoratorTestEvent $e): void { $this->called = true; }
        };

        $registry  = $this->registryWithProjection('my-consumer', 'handler.service');
        $inner     = $this->createMock(EventBusInterface::class);
        $decorator = $this->makeDecorator($inner, $registry, ['handler.service' => $handler]);

        $decorator->dispatch($this->envelope(new DecoratorTestEvent()));

        $this->assertTrue($called);
    }

    public function test_invokes_correct_method_on_handler(): void
    {
        $handler = new class {
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
        ]);

        $this->makeDecorator(
            $this->createMock(EventBusInterface::class),
            $registry,
            ['svc' => $handler],
        )->dispatch($this->envelope(new DecoratorTestEvent()));

        $this->assertTrue($handler->methodCalled);
    }

    public function test_skips_non_projection_handlers(): void
    {
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
            'isProjection' => false,
        ]);

        $this->makeDecorator(
            $this->createMock(EventBusInterface::class),
            $registry,
            ['svc' => $handler],
        )->dispatch($this->envelope(new DecoratorTestEvent()));

        $this->assertFalse($called);
    }

    public function test_swallows_projection_exception_and_logs_warning(): void
    {
        $handler = new class {
            public function __invoke(DecoratorTestEvent $e): void
            {
                throw new \RuntimeException('projection broke');
            }
        };

        $registry  = $this->registryWithProjection('consumer', 'svc');
        $inner     = $this->createMock(EventBusInterface::class);
        $decorator = $this->makeDecorator($inner, $registry, ['svc' => $handler]);

        $decorator->dispatch($this->envelope(new DecoratorTestEvent()));
        $this->assertTrue(true);
    }

    public function test_projection_exception_does_not_suppress_inner_dispatch(): void
    {
        $handler = new class {
            public function __invoke(DecoratorTestEvent $e): void
            {
                throw new \RuntimeException('projection broke');
            }
        };

        $registry = $this->registryWithProjection('consumer', 'svc');
        $inner    = $this->createMock(EventBusInterface::class);
        $inner->expects($this->once())->method('dispatch');

        $this->makeDecorator($inner, $registry, ['svc' => $handler])
            ->dispatch($this->envelope(new DecoratorTestEvent()));
    }

    public function test_inner_exception_propagates_unchanged(): void
    {
        $inner = $this->createMock(EventBusInterface::class);
        $inner->method('dispatch')->willThrowException(new \RuntimeException('kafka down'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('kafka down');

        $this->makeDecorator($inner, $this->emptyRegistry())
            ->dispatch($this->envelope(new DecoratorTestEvent()));
    }

    public function test_multiple_projection_handlers_all_invoked(): void
    {
        $count = 0;
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
            ]);
        }

        $this->makeDecorator(
            $this->createMock(EventBusInterface::class),
            $registry,
            ['h1' => $handler, 'h2' => $handler, 'h3' => $handler],
        )->dispatch($this->envelope(new DecoratorTestEvent()));

        $this->assertSame(3, $count);
    }

    public function test_dispatch_batch_processes_each_envelope(): void
    {
        $count = 0;
        $handler = new class($count) {
            public function __construct(private int &$count) {}
            public function __invoke(DecoratorTestEvent $e): void { $this->count++; }
        };

        $registry = $this->registryWithProjection('consumer', 'svc');
        $inner    = $this->createMock(EventBusInterface::class);
        $inner->expects($this->exactly(2))->method('dispatch');

        $decorator = $this->makeDecorator($inner, $registry, ['svc' => $handler]);
        $decorator->dispatchBatch(
            $this->envelope(new DecoratorTestEvent('a')),
            $this->envelope(new DecoratorTestEvent('b')),
        );

        $this->assertSame(2, $count);
    }

    public function test_injects_event_envelope_when_handler_declares_it(): void
    {
        $received  = null;
        $handler   = new class($received) {
            public function __construct(private mixed &$received) {}
            public function __invoke(DecoratorTestEvent $e, EventEnvelope $env): void
            {
                $this->received = $env;
            }
        };

        $registry  = $this->registryWithProjection('consumer', 'svc');
        $envelope  = $this->envelope(new DecoratorTestEvent());
        $decorator = $this->makeDecorator(
            $this->createMock(EventBusInterface::class),
            $registry,
            ['svc' => $handler],
        );

        $decorator->dispatch($envelope);

        $this->assertSame($envelope, $received);
    }

    public function test_injects_metadata_when_handler_declares_it(): void
    {
        $received = null;
        $handler  = new class($received) {
            public function __construct(private mixed &$received) {}
            public function __invoke(DecoratorTestEvent $e, Metadata $meta): void
            {
                $this->received = $meta;
            }
        };

        $metadata  = new Metadata(correlationId: 'corr-abc');
        $envelope  = new EventEnvelope(
            eventId: 'evt-001', aggregateId: 'agg-001', aggregateType: 'T',
            aggregateVersion: 1, payloadType: DecoratorTestEvent::class,
            schemaVersion: 1, occurredAt: new \DateTimeImmutable(),
            payload: new DecoratorTestEvent(), metadata: $metadata,
        );

        $registry  = $this->registryWithProjection('consumer', 'svc');
        $decorator = $this->makeDecorator(
            $this->createMock(EventBusInterface::class),
            $registry,
            ['svc' => $handler],
        );

        $decorator->dispatch($envelope);

        $this->assertSame($metadata, $received);
        $this->assertSame('corr-abc', $received->correlationId);
    }

    public function test_handler_with_no_params_does_not_throw(): void
    {
        $called  = false;
        $handler = new class($called) {
            public function __construct(private bool &$called) {}
            public function __invoke(): void { $this->called = true; }
        };

        $registry  = $this->registryWithProjection('consumer', 'svc');
        $decorator = $this->makeDecorator(
            $this->createMock(EventBusInterface::class),
            $registry,
            ['svc' => $handler],
        );

        $decorator->dispatch($this->envelope(new DecoratorTestEvent()));

        $this->assertTrue($called);
    }
}
