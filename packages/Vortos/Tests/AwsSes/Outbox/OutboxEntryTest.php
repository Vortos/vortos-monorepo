<?php

declare(strict_types=1);

namespace Vortos\Tests\AwsSes\Outbox;

use PHPUnit\Framework\TestCase;
use Vortos\AwsSes\Outbox\OutboxEntry;
use Vortos\AwsSes\Outbox\OutboxStatus;

final class OutboxEntryTest extends TestCase
{
    private function makeEntry(OutboxStatus $status, ?string $awsMessageId = null): OutboxEntry
    {
        return new OutboxEntry(
            outboxId:     'a0000000-0000-7000-8000-000000000001',
            status:       $status,
            awsMessageId: $awsMessageId,
            attemptCount: 0,
            createdAt:    new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            sentAt:       null,
            lastError:    null,
        );
    }

    public function test_is_delivered_true_when_sent(): void
    {
        $this->assertTrue($this->makeEntry(OutboxStatus::Sent)->isDelivered());
    }

    public function test_is_delivered_false_when_pending(): void
    {
        $this->assertFalse($this->makeEntry(OutboxStatus::Pending)->isDelivered());
    }

    public function test_is_delivered_false_when_dead(): void
    {
        $this->assertFalse($this->makeEntry(OutboxStatus::Dead)->isDelivered());
    }

    public function test_is_pending_true_when_pending(): void
    {
        $this->assertTrue($this->makeEntry(OutboxStatus::Pending)->isPending());
    }

    public function test_is_pending_true_when_processing(): void
    {
        $this->assertTrue($this->makeEntry(OutboxStatus::Processing)->isPending());
    }

    public function test_is_pending_false_when_sent(): void
    {
        $this->assertFalse($this->makeEntry(OutboxStatus::Sent)->isPending());
    }

    public function test_is_dead_true_when_dead(): void
    {
        $this->assertTrue($this->makeEntry(OutboxStatus::Dead)->isDead());
    }

    public function test_is_dead_false_when_sent(): void
    {
        $this->assertFalse($this->makeEntry(OutboxStatus::Sent)->isDead());
    }

    public function test_aws_message_id_is_null_before_relay(): void
    {
        $this->assertNull($this->makeEntry(OutboxStatus::Pending)->awsMessageId);
    }

    public function test_aws_message_id_populated_after_delivery(): void
    {
        $entry = $this->makeEntry(OutboxStatus::Sent, '0102030405060708-abc-def');
        $this->assertSame('0102030405060708-abc-def', $entry->awsMessageId);
    }
}
