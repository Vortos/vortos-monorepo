<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Tests\Middleware;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Vortos\AwsSes\Middleware\AuditLogMiddleware;
use Vortos\AwsSes\ValueObject\Email;
use Vortos\AwsSes\ValueObject\SentEmail;

final class AuditLogMiddlewareTest extends TestCase
{
    private function makeSent(string $messageId = 'msg-1'): SentEmail
    {
        return new SentEmail(
            messageId:      $messageId,
            sentAt:         new DateTimeImmutable('2024-01-01 12:00:00'),
            recipientCount: 1,
            driver:         'ses',
            region:         'us-east-1',
        );
    }

    private function makeEmail(): Email
    {
        return Email::new()
            ->from('sender@example.com')
            ->to('recipient@example.com')
            ->subject('Hello');
    }

    public function test_inserts_audit_record_on_success(): void
    {
        $inserted = [];
        $conn     = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (string $table, array $data) use (&$inserted): int {
                $inserted = $data;
                return 1;
            });

        $sent       = $this->makeSent();
        $middleware = new AuditLogMiddleware($conn, new NullLogger(), 'aws_ses_audit_log');
        $result     = $middleware->process($this->makeEmail(), fn($e) => $sent);

        $this->assertSame($sent, $result);
        $this->assertSame('msg-1', $inserted['message_id']);
        $this->assertSame('ses',   $inserted['driver']);
        $this->assertSame('us-east-1', $inserted['region']);
        $this->assertStringContainsString('recipient@example.com', $inserted['recipients']);
        $this->assertSame('Hello', $inserted['subject']);
    }

    public function test_inserts_into_configured_table(): void
    {
        $usedTable = '';
        $conn      = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (string $table, array $data) use (&$usedTable): int {
                $usedTable = $table;
                return 1;
            });

        $middleware = new AuditLogMiddleware($conn, new NullLogger(), 'custom_audit');
        $middleware->process($this->makeEmail(), fn($e) => $this->makeSent());

        $this->assertSame('custom_audit', $usedTable);
    }

    public function test_returns_sent_email_from_next(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('insert')->willReturn(1);

        $sent       = $this->makeSent('unique-msg-id');
        $middleware = new AuditLogMiddleware($conn, new NullLogger(), 'aws_ses_audit_log');
        $result     = $middleware->process($this->makeEmail(), fn($e) => $sent);

        $this->assertSame('unique-msg-id', $result->messageId());
    }

    public function test_db_failure_does_not_block_delivery(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('insert')->willThrowException(new \RuntimeException('DB down'));

        $sent       = $this->makeSent();
        $middleware = new AuditLogMiddleware($conn, new NullLogger(), 'aws_ses_audit_log');

        // Should NOT throw — DB failure is swallowed
        $result = $middleware->process($this->makeEmail(), fn($e) => $sent);
        $this->assertSame($sent, $result);
    }

    public function test_outbox_id_meta_is_recorded(): void
    {
        $inserted = [];
        $conn     = $this->createMock(Connection::class);
        $conn->method('insert')
            ->willReturnCallback(function (string $table, array $data) use (&$inserted): int {
                $inserted = $data;
                return 1;
            });

        $email = $this->makeEmail()->withMeta('outbox_id', 'outbox-42');

        $middleware = new AuditLogMiddleware($conn, new NullLogger(), 'aws_ses_audit_log');
        $middleware->process($email, fn($e) => $this->makeSent());

        $this->assertSame('outbox-42', $inserted['outbox_id']);
    }

    public function test_propagates_send_exceptions(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->never())->method('insert');

        $middleware = new AuditLogMiddleware($conn, new NullLogger(), 'aws_ses_audit_log');

        $this->expectException(\RuntimeException::class);
        $middleware->process($this->makeEmail(), fn($e) => throw new \RuntimeException('send failed'));
    }
}
