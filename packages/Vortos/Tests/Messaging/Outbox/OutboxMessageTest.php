<?php

declare(strict_types=1);

namespace Vortos\Tests\Messaging\Outbox;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Messaging\Outbox\OutboxMessage;

final class OutboxMessageTest extends TestCase
{
    private function row(array $overrides = []): array
    {
        return array_merge([
            'id'                => 'msg-uuid',
            'transport_name'    => 'user.events',
            'event_id'          => 'evt-uuid',
            'aggregate_id'      => 'agg-123',
            'aggregate_type'    => 'App\\User\\Domain\\User',
            'aggregate_version' => '3',
            'payload_type'      => 'App\\User\\Domain\\Event\\UserRegistered',
            'schema_version'    => '1',
            'occurred_at'       => '2026-01-15 10:00:00',
            'correlation_id'    => 'corr-abc',
            'causation_id'      => 'caus-def',
            'trace_id'          => 'trace-ghi',
            'metadata'          => null,
            'payload'           => '{"userId":"123"}',
            'status'            => 'pending',
            'attempt_count'     => '0',
            'created_at'        => '2026-01-15 10:00:01',
            'published_at'      => null,
            'next_attempt_at'   => null,
            'failure_reason'    => null,
        ], $overrides);
    }

    public function test_from_database_row_maps_all_scalar_fields(): void
    {
        $msg = OutboxMessage::fromDatabaseRow($this->row());

        $this->assertSame('msg-uuid', $msg->id);
        $this->assertSame('user.events', $msg->transportName);
        $this->assertSame('evt-uuid', $msg->eventId);
        $this->assertSame('agg-123', $msg->aggregateId);
        $this->assertSame('App\\User\\Domain\\User', $msg->aggregateType);
        $this->assertSame(3, $msg->aggregateVersion);
        $this->assertSame('App\\User\\Domain\\Event\\UserRegistered', $msg->payloadType);
        $this->assertSame(1, $msg->schemaVersion);
        $this->assertSame('corr-abc', $msg->correlationId);
        $this->assertSame('caus-def', $msg->causationId);
        $this->assertSame('trace-ghi', $msg->traceId);
        $this->assertSame('{"userId":"123"}', $msg->payload);
        $this->assertSame('pending', $msg->status);
        $this->assertSame(0, $msg->attemptCount);
        $this->assertNull($msg->publishedAt);
        $this->assertNull($msg->nextAttemptAt);
        $this->assertNull($msg->failureReason);
    }

    public function test_from_database_row_coerces_integers_from_strings(): void
    {
        $msg = OutboxMessage::fromDatabaseRow($this->row([
            'aggregate_version' => '7',
            'schema_version'    => '2',
            'attempt_count'     => '3',
        ]));

        $this->assertSame(7, $msg->aggregateVersion);
        $this->assertSame(2, $msg->schemaVersion);
        $this->assertSame(3, $msg->attemptCount);
    }

    public function test_from_database_row_parses_datetime_fields(): void
    {
        $msg = OutboxMessage::fromDatabaseRow($this->row([
            'occurred_at'    => '2026-01-15 10:00:00',
            'created_at'     => '2026-01-15 10:00:01',
            'published_at'   => '2026-01-15 10:05:00',
            'next_attempt_at'=> '2026-01-15 10:30:00',
        ]));

        $this->assertInstanceOf(DateTimeImmutable::class, $msg->occurredAt);
        $this->assertInstanceOf(DateTimeImmutable::class, $msg->createdAt);
        $this->assertInstanceOf(DateTimeImmutable::class, $msg->publishedAt);
        $this->assertInstanceOf(DateTimeImmutable::class, $msg->nextAttemptAt);
    }

    public function test_from_database_row_decodes_metadata_json(): void
    {
        $msg = OutboxMessage::fromDatabaseRow($this->row([
            'metadata' => '{"tenantId":"t1","userId":"u1","custom_key":"value"}',
        ]));

        $this->assertSame('t1', $msg->metadata['tenantId']);
        $this->assertSame('u1', $msg->metadata['userId']);
        $this->assertSame('value', $msg->metadata['custom_key']);
    }

    public function test_from_database_row_returns_empty_array_for_null_metadata(): void
    {
        $msg = OutboxMessage::fromDatabaseRow($this->row(['metadata' => null]));
        $this->assertSame([], $msg->metadata);
    }

    public function test_from_database_row_returns_empty_array_for_empty_string_metadata(): void
    {
        $msg = OutboxMessage::fromDatabaseRow($this->row(['metadata' => '']));
        $this->assertSame([], $msg->metadata);
    }

    public function test_from_database_row_handles_null_optional_fields(): void
    {
        $msg = OutboxMessage::fromDatabaseRow($this->row([
            'correlation_id'  => null,
            'causation_id'    => null,
            'trace_id'        => null,
            'published_at'    => null,
            'next_attempt_at' => null,
            'failure_reason'  => null,
        ]));

        $this->assertNull($msg->correlationId);
        $this->assertNull($msg->causationId);
        $this->assertNull($msg->traceId);
        $this->assertNull($msg->publishedAt);
        $this->assertNull($msg->nextAttemptAt);
        $this->assertNull($msg->failureReason);
    }
}
