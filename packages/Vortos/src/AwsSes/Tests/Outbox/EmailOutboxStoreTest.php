<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Tests\Outbox;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Vortos\AwsSes\Outbox\EmailOutboxStore;
use Vortos\AwsSes\Outbox\OutboxStatus;

final class EmailOutboxStoreTest extends TestCase
{
    private const TABLE = 'aws_ses_outbox';

    private function makeStore(Connection $conn): EmailOutboxStore
    {
        return new EmailOutboxStore($conn, self::TABLE);
    }

    private function makeRow(array $overrides = []): array
    {
        return array_merge([
            'id'           => 'a0000000-0000-7000-8000-000000000001',
            'status'       => 'sent',
            'message_id'   => '0102030405060708-abc-def',
            'attempt_count' => 1,
            'created_at'   => '2026-01-01 10:00:00',
            'sent_at'      => '2026-01-01 10:05:00',
            'last_error'   => null,
        ], $overrides);
    }

    public function test_find_by_id_returns_null_when_not_found(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')->willReturn(false);

        $this->assertNull($this->makeStore($conn)->findById('unknown-id'));
    }

    public function test_find_by_id_returns_hydrated_entry(): void
    {
        $row  = $this->makeRow();
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')->willReturn($row);

        $entry = $this->makeStore($conn)->findById($row['id']);

        $this->assertNotNull($entry);
        $this->assertSame($row['id'], $entry->outboxId);
        $this->assertSame(OutboxStatus::Sent, $entry->status);
        $this->assertSame($row['message_id'], $entry->awsMessageId);
        $this->assertSame(1, $entry->attemptCount);
        $this->assertInstanceOf(\DateTimeImmutable::class, $entry->createdAt);
        $this->assertInstanceOf(\DateTimeImmutable::class, $entry->sentAt);
        $this->assertNull($entry->lastError);
    }

    public function test_find_by_id_returns_null_aws_message_id_when_not_yet_sent(): void
    {
        $row  = $this->makeRow(['status' => 'pending', 'message_id' => null, 'sent_at' => null]);
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')->willReturn($row);

        $entry = $this->makeStore($conn)->findById($row['id']);

        $this->assertNotNull($entry);
        $this->assertNull($entry->awsMessageId);
        $this->assertNull($entry->sentAt);
        $this->assertTrue($entry->isPending());
    }

    public function test_find_by_aws_message_id_returns_null_when_not_found(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')->willReturn(false);

        $this->assertNull($this->makeStore($conn)->findByAwsMessageId('unknown-ses-id'));
    }

    public function test_find_by_aws_message_id_returns_hydrated_entry(): void
    {
        $row  = $this->makeRow();
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')->willReturn($row);

        $entry = $this->makeStore($conn)->findByAwsMessageId($row['message_id']);

        $this->assertNotNull($entry);
        $this->assertSame($row['message_id'], $entry->awsMessageId);
        $this->assertTrue($entry->isDelivered());
    }

    public function test_last_error_is_hydrated_when_present(): void
    {
        $row  = $this->makeRow(['status' => 'pending', 'message_id' => null, 'sent_at' => null, 'last_error' => 'Connection timeout']);
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')->willReturn($row);

        $entry = $this->makeStore($conn)->findById($row['id']);

        $this->assertSame('Connection timeout', $entry?->lastError);
    }

    public function test_dead_status_is_hydrated_correctly(): void
    {
        $row  = $this->makeRow(['status' => 'dead', 'message_id' => null, 'sent_at' => null, 'attempt_count' => 3]);
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')->willReturn($row);

        $entry = $this->makeStore($conn)->findById($row['id']);

        $this->assertNotNull($entry);
        $this->assertTrue($entry->isDead());
        $this->assertSame(3, $entry->attemptCount);
    }
}
