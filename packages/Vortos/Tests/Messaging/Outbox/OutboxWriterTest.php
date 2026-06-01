<?php

declare(strict_types=1);

namespace Vortos\Tests\Messaging\Outbox;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Vortos\Domain\Event\EventEnvelope;
use Vortos\Domain\Event\Metadata;
use Vortos\Messaging\Contract\SerializerInterface;
use Vortos\Messaging\Outbox\OutboxWriter;
use Vortos\Messaging\Serializer\SerializerLocator;
use Vortos\Persistence\Transaction\TransactionRequiredException;

// Pure POPO payload
final readonly class WriterTestPayload
{
    public function __construct(
        public string $userId,
        public string $email,
    ) {}
}

final class OutboxWriterTest extends TestCase
{
    private function makeEnvelope(object $payload, Metadata $metadata = new Metadata()): EventEnvelope
    {
        return new EventEnvelope(
            eventId:          'evt-00000000-0000-7000-8000-000000000001',
            aggregateId:      'user-agg-001',
            aggregateType:    'App\\User\\Domain\\User',
            aggregateVersion: 2,
            payloadType:      $payload::class,
            schemaVersion:    1,
            occurredAt:       new DateTimeImmutable('2026-01-15T10:00:00Z'),
            payload:          $payload,
            metadata:         $metadata,
        );
    }

    private function makeWriter(Connection $connection): OutboxWriter
    {
        $connection->method('isTransactionActive')->willReturn(true);
        $serializer = new class implements SerializerInterface {
            public function supports(string $format): bool { return true; }
            public function serialize(object $payload): string { return '{"serialized":true}'; }
            public function deserialize(string $payload, string $payloadClass): object { return new \stdClass(); }
        };

        $locator = new SerializerLocator([$serializer]);
        return new OutboxWriter($connection, $locator);
    }

    public function test_store_inserts_all_envelope_columns(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection->expects($this->once())
            ->method('insert')
            ->with(
                'messaging_outbox',
                $this->callback(function (array $data): bool {
                    $this->assertArrayHasKey('id', $data);
                    $this->assertArrayHasKey('event_id', $data);
                    $this->assertArrayHasKey('aggregate_id', $data);
                    $this->assertArrayHasKey('aggregate_type', $data);
                    $this->assertArrayHasKey('aggregate_version', $data);
                    $this->assertArrayHasKey('payload_type', $data);
                    $this->assertArrayHasKey('schema_version', $data);
                    $this->assertArrayHasKey('occurred_at', $data);
                    $this->assertArrayHasKey('payload', $data);
                    $this->assertArrayHasKey('status', $data);
                    $this->assertArrayHasKey('attempt_count', $data);
                    return true;
                }),
            );

        $writer = $this->makeWriter($connection);
        $writer->store($this->makeEnvelope(new WriterTestPayload('u1', 'u@x.com')), 'user.events');
    }

    public function test_store_persists_transport_name(): void
    {
        $connection = $this->createMock(Connection::class);
        $captured   = null;

        $connection->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (string $table, array $data) use (&$captured) {
                $captured = $data;
                return 1;
            });

        $this->makeWriter($connection)->store(
            $this->makeEnvelope(new WriterTestPayload('u1', 'u@x.com')),
            'my.transport',
        );

        $this->assertSame('my.transport', $captured['transport_name']);
    }

    public function test_store_persists_envelope_metadata_fields(): void
    {
        $connection = $this->createMock(Connection::class);
        $captured   = null;

        $connection->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (string $table, array $data) use (&$captured) {
                $captured = $data;
                return 1;
            });

        $metadata = new Metadata(
            correlationId: 'corr-111',
            causationId:   'caus-222',
            traceId:       'trace-333',
        );

        $this->makeWriter($connection)->store(
            $this->makeEnvelope(new WriterTestPayload('u1', 'u@x.com'), $metadata),
            'user.events',
        );

        $this->assertSame('corr-111', $captured['correlation_id']);
        $this->assertSame('caus-222', $captured['causation_id']);
        $this->assertSame('trace-333', $captured['trace_id']);
    }

    public function test_store_encodes_tenant_and_user_into_metadata_json(): void
    {
        $connection = $this->createMock(Connection::class);
        $captured   = null;

        $connection->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (string $table, array $data) use (&$captured) {
                $captured = $data;
                return 1;
            });

        $metadata = new Metadata(tenantId: 'tenant-abc', userId: 'user-xyz');

        $this->makeWriter($connection)->store(
            $this->makeEnvelope(new WriterTestPayload('u1', 'u@x.com'), $metadata),
            'user.events',
        );

        $decoded = json_decode($captured['metadata'], true);
        $this->assertSame('tenant-abc', $decoded['tenantId']);
        $this->assertSame('user-xyz', $decoded['userId']);
    }

    public function test_store_sets_null_metadata_when_no_extra_fields(): void
    {
        $connection = $this->createMock(Connection::class);
        $captured   = null;

        $connection->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (string $table, array $data) use (&$captured) {
                $captured = $data;
                return 1;
            });

        $this->makeWriter($connection)->store(
            $this->makeEnvelope(new WriterTestPayload('u1', 'u@x.com'), new Metadata()),
            'user.events',
        );

        $this->assertNull($captured['metadata']);
    }

    public function test_store_sets_status_pending_and_attempt_count_zero(): void
    {
        $connection = $this->createMock(Connection::class);
        $captured   = null;

        $connection->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (string $table, array $data) use (&$captured) {
                $captured = $data;
                return 1;
            });

        $this->makeWriter($connection)->store(
            $this->makeEnvelope(new WriterTestPayload('u1', 'u@x.com')),
            'user.events',
        );

        $this->assertSame('pending', $captured['status']);
        $this->assertSame(0, $captured['attempt_count']);
    }

    public function test_store_requires_active_transaction(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->expects($this->never())->method('insert');

        $serializer = new class implements SerializerInterface {
            public function supports(string $format): bool { return true; }
            public function serialize(object $payload): string { return '{"serialized":true}'; }
            public function deserialize(string $payload, string $payloadClass): object { return new \stdClass(); }
        };

        $this->expectException(TransactionRequiredException::class);
        (new OutboxWriter($connection, new SerializerLocator([$serializer])))->store(
            $this->makeEnvelope(new WriterTestPayload('u1', 'u@x.com')),
            'user.events',
        );
    }
}
