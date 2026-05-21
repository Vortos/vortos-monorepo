<?php

declare(strict_types=1);

namespace Vortos\Tests\Messaging\Bus;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Envelope as MessengerEnvelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Vortos\Domain\Event\EventEnvelope;
use Vortos\Domain\Event\Metadata;
use Vortos\Messaging\Bus\EventBus;
use Vortos\Messaging\Contract\OutboxInterface;
use Vortos\Messaging\Contract\ProducerInterface;
use Vortos\Messaging\Hook\HookRegistry;
use Vortos\Messaging\Hook\HookRunner;
use Vortos\Messaging\Registry\ConsumerRegistry;
use Vortos\Messaging\Registry\HandlerRegistry;
use Vortos\Messaging\Registry\ProducerRegistry;

// Pure POPO
final readonly class BusTestPayload
{
    public function __construct(
        public string $value = 'test',
    ) {}
}

final class EventBusTest extends TestCase
{
    private function makeEnvelope(
        object $payload,
        Metadata $metadata = new Metadata(),
        string $aggregateId = 'agg-001',
    ): EventEnvelope {
        return new EventEnvelope(
            eventId:          'evt-00000000-0000-7000-8000-000000000001',
            aggregateId:      $aggregateId,
            aggregateType:    'TestAggregate',
            aggregateVersion: 1,
            payloadType:      $payload::class,
            schemaVersion:    1,
            occurredAt:       new DateTimeImmutable('2026-01-15T10:00:00Z'),
            payload:          $payload,
            metadata:         $metadata,
        );
    }

    private function makeHookRunner(): HookRunner
    {
        return new HookRunner(
            new HookRegistry([]),
            new ServiceLocator([]),
            new NullLogger(),
        );
    }

    private function makeEventBus(
        MessageBusInterface $messenger,
        OutboxInterface $outbox,
        ProducerInterface $producer,
        HandlerRegistry $handlerRegistry,
        ProducerRegistry $producerRegistry,
        array $eventProducerMap = [],
        ConsumerRegistry $consumerRegistry = null,
    ): EventBus {
        return new EventBus(
            $messenger,
            $outbox,
            $producer,
            $handlerRegistry,
            $producerRegistry,
            $eventProducerMap,
            $this->makeHookRunner(),
            new NullLogger(),
            null,
            $consumerRegistry ?? new ConsumerRegistry([]),
        );
    }

    public function test_dispatches_to_messenger_when_in_process_consumer_registered(): void
    {
        $payload  = new BusTestPayload();
        $envelope = $this->makeEnvelope($payload);

        $handlerRegistry = new HandlerRegistry([]);
        $handlerRegistry->registerHandler('my-consumer', BusTestPayload::class, [
            'handlerId'    => 'h1',
            'serviceId'    => 'svc',
            'method'       => '__invoke',
            'priority'     => 0,
            'idempotent'   => false,
            'isProjection' => false,
        ]);

        $consumerRegistry = new ConsumerRegistry([
            'my-consumer' => ['inProcess' => true, 'transport' => ''],
        ]);

        $messenger = $this->createMock(MessageBusInterface::class);
        $messenger->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(fn(MessengerEnvelope $e): bool =>
                $e->getMessage() === $payload
            ))
            ->willReturnArgument(0);

        $bus = $this->makeEventBus(
            $messenger,
            $this->createMock(OutboxInterface::class),
            $this->createMock(ProducerInterface::class),
            $handlerRegistry,
            new ProducerRegistry([]),
            [],
            $consumerRegistry,
        );

        $bus->dispatch($envelope);
    }

    public function test_does_not_dispatch_to_messenger_when_no_in_process_consumer(): void
    {
        $envelope = $this->makeEnvelope(new BusTestPayload());

        $messenger = $this->createMock(MessageBusInterface::class);
        $messenger->expects($this->never())->method('dispatch');

        $bus = $this->makeEventBus(
            $messenger,
            $this->createMock(OutboxInterface::class),
            $this->createMock(ProducerInterface::class),
            new HandlerRegistry([]),
            new ProducerRegistry([]),
        );

        $bus->dispatch($envelope);
    }

    public function test_stores_to_outbox_when_producer_registered_with_outbox_enabled(): void
    {
        $payload  = new BusTestPayload();
        $envelope = $this->makeEnvelope($payload);

        $producerRegistry = new ProducerRegistry([
            'my-producer' => ['outbox' => ['enabled' => true], 'transport' => 'user.events'],
        ]);

        $outbox = $this->createMock(OutboxInterface::class);
        $outbox->expects($this->once())
            ->method('store')
            ->with($this->anything(), 'user.events');

        $bus = $this->makeEventBus(
            $this->createMock(MessageBusInterface::class),
            $outbox,
            $this->createMock(ProducerInterface::class),
            new HandlerRegistry([]),
            $producerRegistry,
            [BusTestPayload::class => 'my-producer'],
        );

        $bus->dispatch($envelope);
    }

    public function test_produces_directly_when_outbox_disabled(): void
    {
        $payload  = new BusTestPayload();
        $envelope = $this->makeEnvelope($payload);

        $producerRegistry = new ProducerRegistry([
            'my-producer' => ['outbox' => ['enabled' => false], 'transport' => 'user.events'],
        ]);

        $producer = $this->createMock(ProducerInterface::class);
        $producer->expects($this->once())
            ->method('produce')
            ->with('user.events', $payload, $this->isType('array'));

        $outbox = $this->createMock(OutboxInterface::class);
        $outbox->expects($this->never())->method('store');

        $bus = $this->makeEventBus(
            $this->createMock(MessageBusInterface::class),
            $outbox,
            $producer,
            new HandlerRegistry([]),
            $producerRegistry,
            [BusTestPayload::class => 'my-producer'],
        );

        $bus->dispatch($envelope);
    }

    public function test_headers_include_all_envelope_fields(): void
    {
        $payload  = new BusTestPayload();
        $metadata = new Metadata(correlationId: 'corr-123', causationId: 'caus-456', traceId: 'trace-789');
        $envelope = $this->makeEnvelope($payload, $metadata, 'agg-special');

        $producerRegistry = new ProducerRegistry([
            'p' => ['outbox' => ['enabled' => false], 'transport' => 't'],
        ]);

        $producer = $this->createMock(ProducerInterface::class);
        $producer->expects($this->once())
            ->method('produce')
            ->with(
                't',
                $payload,
                $this->callback(function (array $headers): bool {
                    $this->assertSame('agg-special', $headers['aggregate_id']);
                    $this->assertSame(BusTestPayload::class, $headers['payload_type']);
                    $this->assertSame('corr-123', $headers['correlation_id']);
                    $this->assertSame('caus-456', $headers['causation_id']);
                    $this->assertSame('trace-789', $headers['trace_id']);
                    return true;
                }),
            );

        $bus = $this->makeEventBus(
            $this->createMock(MessageBusInterface::class),
            $this->createMock(OutboxInterface::class),
            $producer,
            new HandlerRegistry([]),
            $producerRegistry,
            [BusTestPayload::class => 'p'],
        );

        $bus->dispatch($envelope);
    }

    public function test_enriches_metadata_with_correlation_id_when_absent(): void
    {
        $payload  = new BusTestPayload();
        // No correlationId set
        $envelope = $this->makeEnvelope($payload, new Metadata());

        $producerRegistry = new ProducerRegistry([
            'p' => ['outbox' => ['enabled' => false], 'transport' => 't'],
        ]);

        $capturedHeaders = null;
        $producer = $this->createMock(ProducerInterface::class);
        $producer->expects($this->once())
            ->method('produce')
            ->willReturnCallback(function (string $transport, object $p, array $headers) use (&$capturedHeaders) {
                $capturedHeaders = $headers;
            });

        $bus = $this->makeEventBus(
            $this->createMock(MessageBusInterface::class),
            $this->createMock(OutboxInterface::class),
            $producer,
            new HandlerRegistry([]),
            $producerRegistry,
            [BusTestPayload::class => 'p'],
        );

        $bus->dispatch($envelope);

        $this->assertNotEmpty($capturedHeaders['correlation_id']);
    }

    public function test_dispatch_batch_dispatches_each_envelope(): void
    {
        $outbox = $this->createMock(OutboxInterface::class);
        $outbox->expects($this->exactly(3))->method('store');

        $producerRegistry = new ProducerRegistry([
            'p' => ['outbox' => ['enabled' => true], 'transport' => 't'],
        ]);

        $bus = $this->makeEventBus(
            $this->createMock(MessageBusInterface::class),
            $outbox,
            $this->createMock(ProducerInterface::class),
            new HandlerRegistry([]),
            $producerRegistry,
            [BusTestPayload::class => 'p'],
        );

        $bus->dispatchBatch(
            $this->makeEnvelope(new BusTestPayload('a')),
            $this->makeEnvelope(new BusTestPayload('b')),
            $this->makeEnvelope(new BusTestPayload('c')),
        );
    }

    public function test_exception_propagates_from_inner_dispatch(): void
    {
        $messenger = $this->createMock(MessageBusInterface::class);
        $messenger->method('dispatch')->willThrowException(new \RuntimeException('bus failed'));

        $handlerRegistry = new HandlerRegistry([]);
        $handlerRegistry->registerHandler('c', BusTestPayload::class, [
            'handlerId' => 'h', 'serviceId' => 's', 'method' => '__invoke',
            'priority' => 0, 'idempotent' => false, 'isProjection' => false,
        ]);
        $consumerRegistry = new ConsumerRegistry(['c' => ['inProcess' => true, 'transport' => '']]);

        $bus = $this->makeEventBus(
            $messenger,
            $this->createMock(OutboxInterface::class),
            $this->createMock(ProducerInterface::class),
            $handlerRegistry,
            new ProducerRegistry([]),
            [],
            $consumerRegistry,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('bus failed');

        $bus->dispatch($this->makeEnvelope(new BusTestPayload()));
    }
}
