<?php

declare(strict_types=1);

namespace Vortos\Messaging\Tests\Runtime;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Cache\Contract\AtomicCacheInterface;
use Vortos\Domain\Event\EventEnvelope;
use Vortos\Domain\Event\Metadata;
use Vortos\Messaging\Attribute\Header\CausationId;
use Vortos\Messaging\Attribute\Header\TenantId;
use Vortos\Messaging\Attribute\Header\TraceId;
use Vortos\Messaging\Attribute\Header\UserId;
use Vortos\Messaging\Contract\ConsumerInterface;
use Vortos\Messaging\Contract\ConsumerLocatorInterface;
use Vortos\Messaging\Contract\ProducerInterface;
use Vortos\Messaging\Contract\SerializerInterface;
use Doctrine\DBAL\Connection;
use Vortos\Messaging\DeadLetter\DeadLetterWriter;
use Vortos\Messaging\Middleware\MiddlewareStack;
use Vortos\Messaging\Registry\ConsumerRegistry;
use Vortos\Messaging\Registry\HandlerRegistry;
use Vortos\Messaging\Retry\RetryDecider;
use Vortos\Messaging\Retry\RetryDelayCalculator;
use Vortos\Messaging\Runtime\ConsumerRunner;
use Vortos\Messaging\Serializer\SerializerLocator;
use Vortos\Messaging\ValueObject\ReceivedMessage;

// Pure POPO payload
final readonly class RunnerTestPayload
{
    public function __construct(
        public string $value = 'test',
    ) {}
}

final class RunnerRenameValueUpcaster implements \Vortos\Messaging\Upcasting\UpcasterInterface
{
    public function upcast(array $payload): array
    {
        $payload['value'] = $payload['old_value'] ?? '';
        unset($payload['old_value']);
        return $payload;
    }
}

/**
 * Fake ConsumerInterface: delivers a single message then stops.
 */
final class SingleMessageConsumer implements ConsumerInterface
{
    public bool $acknowledged = false;
    public bool $rejected = false;

    public function __construct(private ReceivedMessage $message) {}

    public function consume(string $consumerName, callable $handler): void
    {
        $handler($this->message);
    }

    public function stop(): void {}

    public function acknowledge(ReceivedMessage $message): void
    {
        $this->acknowledged = true;
    }

    public function reject(ReceivedMessage $message, bool $requeue = false): void
    {
        $this->rejected = true;
    }
}

final class ConsumerRunnerTest extends TestCase
{
    private function makeMessage(array $headers = [], string $payload = '{"value":"test"}'): ReceivedMessage
    {
        return new ReceivedMessage(
            id:            'msg-001',
            payload:       $payload,
            headers:       $headers,
            transportName: 'test.transport',
            receivedAt:    new DateTimeImmutable(),
        );
    }

    /** Logical wire name mapped to the local test payload class. */
    private const WIRE_NAME = 'messaging.runner_test_payload';

    private function baseHeaders(): array
    {
        return [
            'payload_type'      => self::WIRE_NAME . '.v1',
            'event_id'          => 'evt-00000000-0000-7000-8000-000000000001',
            'aggregate_id'      => 'agg-001',
            'aggregate_type'    => 'TestAggregate',
            'aggregate_version' => '3',
            'schema_version'    => '1',
            'occurred_at'       => '2026-01-15T10:00:00+00:00',
            'correlation_id'    => 'corr-111',
            'causation_id'      => 'caus-222',
            'trace_id'          => 'trace-333',
        ];
    }

    private function makeSerializer(): SerializerInterface
    {
        return new class implements SerializerInterface {
            public function supports(string $format): bool { return true; }
            public function serialize(object $payload): string { return '{}'; }
            public function deserialize(string $payload, string $payloadClass): object
            {
                return new RunnerTestPayload('deserialized');
            }
        };
    }

    private function makeRunner(
        HandlerRegistry $registry,
        ConsumerRegistry $consumers,
        SingleMessageConsumer $consumer,
        array $handlers = [],
        SerializerInterface $serializer = null,
        int $idempotencyTtl = 86400,
        ?array $wireEventMap = null,
        array $upcasterMap = [],
    ): ConsumerRunner {
        $cache = new class implements AtomicCacheInterface {
            public function get($key, $default = null): mixed { return $default; }
            public function set($key, $value, $ttl = null): bool { return true; }
            public function delete($key): bool { return true; }
            public function clear(): bool { return true; }
            public function getMultiple($keys, $default = null): iterable { return []; }
            public function setMultiple($values, $ttl = null): bool { return true; }
            public function deleteMultiple($keys): bool { return true; }
            public function has($key): bool { return false; }
            public function setNx(string $key, mixed $value, int $ttl): bool { return true; }
        };

        $locator = new ServiceLocator(
            array_map(fn($h) => fn() => $h, $handlers),
        );

        $dbConn = $this->createMock(Connection::class);
        $dbConn->method('insert')->willReturn(1);
        $dlw = new DeadLetterWriter($dbConn, new NullLogger());

        return new ConsumerRunner(
            handlerRegistry:      $registry,
            serializerLocator:    new SerializerLocator([$serializer ?? $this->makeSerializer()]),
            middlewareStack:      new MiddlewareStack([]),
            deadLetterWriter:     $dlw,
            producer:             $this->createMock(ProducerInterface::class),
            cache:                $cache,
            consumerLocator:      new class($consumer) implements ConsumerLocatorInterface {
                public function __construct(private ConsumerInterface $c) {}
                public function get(string $name): ConsumerInterface { return $this->c; }
            },
            logger:               new NullLogger(),
            handlerLocator:       $locator,
            retryDecider:         new RetryDecider(new RetryDelayCalculator()),
            consumerRegistry:     $consumers,
            defaultIdempotencyTtl: $idempotencyTtl,
            wireEventMap:         $wireEventMap ?? [self::WIRE_NAME => RunnerTestPayload::class],
            upcasterMap:          $upcasterMap,
        );
    }

    public function test_rejects_message_with_no_payload_type_header(): void
    {
        $consumer = new SingleMessageConsumer($this->makeMessage([]));
        $registry = new HandlerRegistry([]);

        $this->makeRunner($registry, new ConsumerRegistry([]), $consumer)->run('my-consumer');

        $this->assertTrue($consumer->rejected);
        $this->assertFalse($consumer->acknowledged);
    }

    public function test_rejects_unknown_wire_contract_without_instantiating(): void
    {
        // payload_type names a contract no consumer declared — closed world.
        $headers = $this->baseHeaders();
        $headers['payload_type'] = 'rogue.unknown_event.v1';

        $consumer = new SingleMessageConsumer($this->makeMessage($headers));
        $registry = new HandlerRegistry([]);

        $this->makeRunner($registry, new ConsumerRegistry([]), $consumer)->run('c');

        $this->assertTrue($consumer->rejected);
        $this->assertFalse($consumer->acknowledged);
    }

    public function test_forged_fqcn_payload_type_is_rejected_not_instantiated(): void
    {
        // Security regression: the wire can no longer select a PHP class.
        // A forged FQCN — even one that exists and is a registered LOCAL class —
        // is not a logical name in the map and must be refused before any
        // deserialization happens.
        $headers = $this->baseHeaders();
        $headers['payload_type'] = RunnerTestPayload::class;

        $serializer = new class implements SerializerInterface {
            public bool $deserializeCalled = false;
            public function supports(string $format): bool { return true; }
            public function serialize(object $payload): string { return '{}'; }
            public function deserialize(string $payload, string $payloadClass): object
            {
                $this->deserializeCalled = true;
                return new RunnerTestPayload();
            }
        };

        $consumer = new SingleMessageConsumer($this->makeMessage($headers));
        $registry = new HandlerRegistry([]);

        $this->makeRunner($registry, new ConsumerRegistry([]), $consumer, serializer: $serializer)->run('c');

        $this->assertTrue($consumer->rejected);
        $this->assertFalse($serializer->deserializeCalled, 'Nothing may be hydrated from an unmapped payload_type');
    }

    public function test_upcaster_chain_lifts_old_payload_before_hydration(): void
    {
        $headers = $this->baseHeaders();
        $headers['payload_type'] = self::WIRE_NAME . '.v1';

        $captured = null;
        $handler  = new class($captured) {
            public function __construct(private mixed &$captured) {}
            public function __invoke(RunnerTestPayload $p, EventEnvelope $envelope): void
            {
                $this->captured = [$p, $envelope];
            }
        };

        $registry = new HandlerRegistry([]);
        $registry->registerHandler('c', RunnerTestPayload::class, [
            'handlerId' => 'h', 'serviceId' => 'svc', 'method' => '__invoke',
            'priority' => 0, 'idempotent' => true, 'isProjection' => false,
            'parameters' => [['type' => 'event'], ['type' => 'envelope']],
        ]);

        $consumer = new SingleMessageConsumer($this->makeMessage($headers, payload: '{"old_value":"upcasted"}'));
        $this->makeRunner(
            $registry,
            new ConsumerRegistry(['c' => ['inProcess' => true]]),
            $consumer,
            ['svc' => $handler],
            serializer: new \Vortos\Messaging\Serializer\JsonSerializer(),
            upcasterMap: [self::WIRE_NAME => [1 => RunnerRenameValueUpcaster::class]],
        )->run('c');

        [$payload, $envelope] = $captured;
        $this->assertSame('upcasted', $payload->value, 'v1 payload must be lifted to v2 shape before hydration');
        $this->assertSame(2, $envelope->schemaVersion, 'Envelope reflects the post-upcast version');
        $this->assertTrue($consumer->acknowledged);
    }

    public function test_version_suffix_is_parsed_into_envelope_schema_version(): void
    {
        $headers = $this->baseHeaders();
        $headers['payload_type'] = self::WIRE_NAME . '.v3';

        $captured = null;
        $handler  = new class($captured) {
            public function __construct(private mixed &$captured) {}
            public function __invoke(RunnerTestPayload $p, EventEnvelope $envelope): void
            {
                $this->captured = $envelope;
            }
        };

        $registry = new HandlerRegistry([]);
        $registry->registerHandler('c', RunnerTestPayload::class, [
            'handlerId' => 'h', 'serviceId' => 'svc', 'method' => '__invoke',
            'priority' => 0, 'idempotent' => true, 'isProjection' => false,
            'parameters' => [['type' => 'event'], ['type' => 'envelope']],
        ]);

        $consumer = new SingleMessageConsumer($this->makeMessage($headers));
        $this->makeRunner(
            $registry,
            new ConsumerRegistry(['c' => ['inProcess' => true]]),
            $consumer,
            ['svc' => $handler],
        )->run('c');

        $this->assertSame(3, $captured->schemaVersion);
        $this->assertSame(RunnerTestPayload::class, $captured->payloadType, 'Envelope carries the LOCAL class in-process');
    }

    public function test_acknowledges_when_no_handlers_registered(): void
    {
        $consumer = new SingleMessageConsumer($this->makeMessage($this->baseHeaders()));
        $registry = new HandlerRegistry([]);

        $this->makeRunner($registry, new ConsumerRegistry([]), $consumer)->run('my-consumer');

        $this->assertTrue($consumer->acknowledged);
        $this->assertFalse($consumer->rejected);
    }

    public function test_rejects_on_deserialization_failure(): void
    {
        $badSerializer = new class implements SerializerInterface {
            public function supports(string $format): bool { return true; }
            public function serialize(object $payload): string { return '{}'; }
            public function deserialize(string $payload, string $payloadClass): object
            {
                throw new \RuntimeException('bad json');
            }
        };

        $registry = new HandlerRegistry([]);
        $registry->registerHandler('c', RunnerTestPayload::class, [
            'handlerId' => 'h', 'serviceId' => 'svc', 'method' => '__invoke',
            'priority' => 0, 'idempotent' => true, 'isProjection' => false,
            'parameters' => [['type' => 'event']],
        ]);

        $consumer = new SingleMessageConsumer($this->makeMessage($this->baseHeaders()));
        $this->makeRunner(
            $registry,
            new ConsumerRegistry(['c' => ['inProcess' => true]]),
            $consumer,
            serializer: $badSerializer,
        )->run('c');

        $this->assertTrue($consumer->rejected);
    }

    public function test_handler_receives_deserialized_payload(): void
    {
        $received = null;
        $handler  = new class($received) {
            public function __construct(private mixed &$received) {}
            public function __invoke(RunnerTestPayload $p): void { $this->received = $p; }
        };

        $registry = new HandlerRegistry([]);
        $registry->registerHandler('c', RunnerTestPayload::class, [
            'handlerId' => 'h', 'serviceId' => 'svc', 'method' => '__invoke',
            'priority' => 0, 'idempotent' => true, 'isProjection' => false,
            'parameters' => [['type' => 'event']],
        ]);

        $consumer = new SingleMessageConsumer($this->makeMessage($this->baseHeaders()));
        $this->makeRunner(
            $registry,
            new ConsumerRegistry(['c' => ['inProcess' => true]]),
            $consumer,
            ['svc' => $handler],
        )->run('c');

        $this->assertInstanceOf(RunnerTestPayload::class, $received);
        $this->assertSame('deserialized', $received->value);
        $this->assertTrue($consumer->acknowledged);
    }

    public function test_injects_event_envelope_into_handler(): void
    {
        $received = null;
        $handler  = new class($received) {
            public function __construct(private mixed &$received) {}
            public function __invoke(RunnerTestPayload $p, EventEnvelope $env): void { $this->received = $env; }
        };

        $registry = new HandlerRegistry([]);
        $registry->registerHandler('c', RunnerTestPayload::class, [
            'handlerId' => 'h', 'serviceId' => 'svc', 'method' => '__invoke',
            'priority' => 0, 'idempotent' => true, 'isProjection' => false,
            'parameters' => [['type' => 'event'], ['type' => 'envelope']],
        ]);

        $consumer = new SingleMessageConsumer($this->makeMessage($this->baseHeaders()));
        $this->makeRunner(
            $registry,
            new ConsumerRegistry(['c' => ['inProcess' => true]]),
            $consumer,
            ['svc' => $handler],
        )->run('c');

        $this->assertInstanceOf(EventEnvelope::class, $received);
        $this->assertSame(RunnerTestPayload::class, $received->payloadType);
        $this->assertSame('agg-001', $received->aggregateId);
        $this->assertSame(3, $received->aggregateVersion);
        $this->assertSame('corr-111', $received->metadata->correlationId);
    }

    public function test_injects_metadata_into_handler(): void
    {
        $received = null;
        $handler  = new class($received) {
            public function __construct(private mixed &$received) {}
            public function __invoke(RunnerTestPayload $p, Metadata $meta): void { $this->received = $meta; }
        };

        $registry = new HandlerRegistry([]);
        $registry->registerHandler('c', RunnerTestPayload::class, [
            'handlerId' => 'h', 'serviceId' => 'svc', 'method' => '__invoke',
            'priority' => 0, 'idempotent' => true, 'isProjection' => false,
            'parameters' => [['type' => 'event'], ['type' => 'metadata']],
        ]);

        $consumer = new SingleMessageConsumer($this->makeMessage($this->baseHeaders()));
        $this->makeRunner(
            $registry,
            new ConsumerRegistry(['c' => ['inProcess' => true]]),
            $consumer,
            ['svc' => $handler],
        )->run('c');

        $this->assertInstanceOf(Metadata::class, $received);
        $this->assertSame('corr-111', $received->correlationId);
        $this->assertSame('caus-222', $received->causationId);
        $this->assertSame('trace-333', $received->traceId);
    }

    public function test_injects_tenant_and_user_id_via_metadata_header_attributes(): void
    {
        $received = null;
        $handler  = new class($received) {
            public function __construct(private mixed &$received) {}
            public function __invoke(
                RunnerTestPayload $p,
                #[TenantId] ?string $tenantId,
                #[UserId] ?string $userId,
                #[CausationId] ?string $causationId,
                #[TraceId] ?string $traceId,
            ): void {
                $this->received = [$tenantId, $userId, $causationId, $traceId];
            }
        };

        $registry = new HandlerRegistry([]);
        $registry->registerHandler('c', RunnerTestPayload::class, [
            'handlerId' => 'h', 'serviceId' => 'svc', 'method' => '__invoke',
            'priority' => 0, 'idempotent' => true, 'isProjection' => false,
            'parameters' => [
                ['type' => 'event'],
                ['type' => 'header', 'attribute' => TenantId::class, 'paramType' => 'string'],
                ['type' => 'header', 'attribute' => UserId::class, 'paramType' => 'string'],
                ['type' => 'header', 'attribute' => CausationId::class, 'paramType' => 'string'],
                ['type' => 'header', 'attribute' => TraceId::class, 'paramType' => 'string'],
            ],
        ]);

        $headers  = array_merge($this->baseHeaders(), ['tenant_id' => 'tenant-abc', 'user_id' => 'user-xyz']);
        $consumer = new SingleMessageConsumer($this->makeMessage($headers));
        $this->makeRunner(
            $registry,
            new ConsumerRegistry(['c' => ['inProcess' => true]]),
            $consumer,
            ['svc' => $handler],
        )->run('c');

        $this->assertSame(['tenant-abc', 'user-xyz', 'caus-222', 'trace-333'], $received);
    }

    public function test_reconstructed_envelope_parses_occurred_at_from_header(): void
    {
        $received = null;
        $handler  = new class($received) {
            public function __construct(private mixed &$received) {}
            public function __invoke(RunnerTestPayload $p, EventEnvelope $env): void { $this->received = $env; }
        };

        $registry = new HandlerRegistry([]);
        $registry->registerHandler('c', RunnerTestPayload::class, [
            'handlerId' => 'h', 'serviceId' => 'svc', 'method' => '__invoke',
            'priority' => 0, 'idempotent' => true, 'isProjection' => false,
            'parameters' => [['type' => 'event'], ['type' => 'envelope']],
        ]);

        $headers  = array_merge($this->baseHeaders(), ['occurred_at' => '2026-01-15T10:00:00+00:00']);
        $consumer = new SingleMessageConsumer($this->makeMessage($headers));
        $this->makeRunner(
            $registry,
            new ConsumerRegistry(['c' => ['inProcess' => true]]),
            $consumer,
            ['svc' => $handler],
        )->run('c');

        $this->assertInstanceOf(\DateTimeImmutable::class, $received->occurredAt);
        $this->assertSame('2026-01-15', $received->occurredAt->format('Y-m-d'));
    }
}
