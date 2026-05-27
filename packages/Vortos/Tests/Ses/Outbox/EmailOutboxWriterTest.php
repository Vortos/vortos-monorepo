<?php

declare(strict_types=1);

namespace Vortos\Tests\Ses\Outbox;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\TestCase;
use Vortos\Ses\Exception\OutboxWriteException;
use Vortos\Ses\Outbox\EmailOutboxWriter;
use Vortos\Ses\ValueObject\Email;

final class EmailOutboxWriterTest extends TestCase
{
    private const TABLE = 'ses_outbox';

    private function makeWriter(Connection $conn): EmailOutboxWriter
    {
        return new EmailOutboxWriter($conn, self::TABLE);
    }

    private function makeEmail(): Email
    {
        return Email::new()->to('u@example.com')->subject('S')->htmlBody('H');
    }

    public function test_inserts_row_to_outbox_table(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('insert')
            ->with(self::TABLE, $this->arrayHasKey('id'));

        $this->makeWriter($conn)->queue($this->makeEmail());
    }

    public function test_sets_status_pending(): void
    {
        $capturedData = null;

        $conn = $this->createMock(Connection::class);
        $conn->method('insert')->willReturnCallback(function ($table, $data) use (&$capturedData) {
            $capturedData = $data;
            return 1;
        });

        $this->makeWriter($conn)->queue($this->makeEmail());

        $this->assertSame('pending', $capturedData['status']);
    }

    public function test_sets_zero_attempt_count(): void
    {
        $capturedData = null;

        $conn = $this->createMock(Connection::class);
        $conn->method('insert')->willReturnCallback(function ($table, $data) use (&$capturedData) {
            $capturedData = $data;
            return 1;
        });

        $this->makeWriter($conn)->queue($this->makeEmail());

        $this->assertSame(0, $capturedData['attempt_count']);
    }

    public function test_stores_domain_event_id_when_provided(): void
    {
        $capturedData = null;

        $conn = $this->createMock(Connection::class);
        $conn->method('insert')->willReturnCallback(function ($table, $data) use (&$capturedData) {
            $capturedData = $data;
            return 1;
        });

        $this->makeWriter($conn)->queue($this->makeEmail(), 'evt-uuid-1');

        $this->assertSame('evt-uuid-1', $capturedData['domain_event_id']);
    }

    public function test_payload_is_valid_json_containing_email_data(): void
    {
        $capturedData = null;

        $conn = $this->createMock(Connection::class);
        $conn->method('insert')->willReturnCallback(function ($table, $data) use (&$capturedData) {
            $capturedData = $data;
            return 1;
        });

        $email = Email::new()->to('user@example.com')->subject('My Subject')->htmlBody('H');
        $this->makeWriter($conn)->queue($email);

        $payload = json_decode($capturedData['payload'], true);
        $this->assertSame('My Subject', $payload['subject']);
    }

    public function test_unique_constraint_violation_is_silently_ignored(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('insert')->willThrowException(
            $this->createMock(UniqueConstraintViolationException::class),
        );

        // Should not throw
        $this->makeWriter($conn)->queue($this->makeEmail(), 'duplicate-event-id');
        $this->assertTrue(true);
    }

    public function test_other_database_errors_throw_outbox_write_exception(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('insert')->willThrowException(new \RuntimeException('DB down'));

        $this->expectException(OutboxWriteException::class);
        $this->makeWriter($conn)->queue($this->makeEmail());
    }
}
