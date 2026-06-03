<?php

declare(strict_types=1);

namespace Vortos\Tests\AwsSes\ValueObject;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\AwsSes\ValueObject\SentEmail;

final class SentEmailTest extends TestCase
{
    public function test_stores_message_id(): void
    {
        $sent = new SentEmail('msg-abc-123', new DateTimeImmutable(), 1);
        $this->assertSame('msg-abc-123', $sent->messageId());
    }

    public function test_stores_sent_at(): void
    {
        $now  = new DateTimeImmutable('2026-01-01 12:00:00');
        $sent = new SentEmail('id', $now, 1);
        $this->assertSame($now, $sent->sentAt());
    }

    public function test_stores_recipient_count(): void
    {
        $sent = new SentEmail('id', new DateTimeImmutable(), 3);
        $this->assertSame(3, $sent->recipientCount());
    }

    public function test_default_driver_is_ses(): void
    {
        $sent = new SentEmail('id', new DateTimeImmutable(), 1);
        $this->assertSame('ses', $sent->driver());
    }

    public function test_custom_driver(): void
    {
        $sent = new SentEmail('id', new DateTimeImmutable(), 1, 'log');
        $this->assertSame('log', $sent->driver());
    }

    public function test_region_defaults_to_null(): void
    {
        $sent = new SentEmail('id', new DateTimeImmutable(), 1);
        $this->assertNull($sent->region());
    }

    public function test_region_stored(): void
    {
        $sent = new SentEmail('id', new DateTimeImmutable(), 1, 'ses', 'eu-west-1');
        $this->assertSame('eu-west-1', $sent->region());
    }

    public function test_is_queued_true_when_driver_is_outbox(): void
    {
        $sent = new SentEmail('outbox-uuid', new DateTimeImmutable(), 1, 'outbox');
        $this->assertTrue($sent->isQueued());
    }

    public function test_is_queued_false_when_driver_is_ses(): void
    {
        $sent = new SentEmail('ses-msg-id', new DateTimeImmutable(), 1, 'ses');
        $this->assertFalse($sent->isQueued());
    }

    public function test_is_queued_false_when_driver_is_log(): void
    {
        $sent = new SentEmail('id', new DateTimeImmutable(), 1, 'log');
        $this->assertFalse($sent->isQueued());
    }
}
