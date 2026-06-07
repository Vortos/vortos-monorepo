<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Tests\Outbox;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Vortos\AwsSes\Contract\MailerInterface;
use Vortos\AwsSes\Exception\MailSendException;
use Vortos\AwsSes\Outbox\EmailOutboxRelay;
use Vortos\AwsSes\Outbox\EmailSerializer;
use Vortos\AwsSes\ValueObject\Email;
use Vortos\AwsSes\ValueObject\SentEmail;

final class EmailOutboxRelayTest extends TestCase
{
    private const TABLE = 'aws_ses_outbox';

    private function makeRelay(Connection $conn, MailerInterface $mailer): EmailOutboxRelay
    {
        return new EmailOutboxRelay(
            connection:          $conn,
            mailer:              $mailer,
            logger:              new NullLogger(),
            clock:               new MockClock(new \DateTimeImmutable('2026-01-01T00:00:00+00:00')),
            tableName:           self::TABLE,
            batchSize:           10,
            maxDeliveryAttempts: 3,
            backoffBaseSeconds:  5,
            backoffCapSeconds:   300,
        );
    }

    private function makeEmail(): Email
    {
        return Email::new()->to('u@example.com')->subject('S')->htmlBody('H');
    }

    private function makeRow(
        string $id = 'outbox-id-1',
        int $attemptCount = 0,
        ?string $domainEventId = null,
    ): array {
        return [
            'id'              => $id,
            'domain_event_id' => $domainEventId,
            'payload'         => json_encode(EmailSerializer::toArray($this->makeEmail())),
            'attempt_count'   => $attemptCount,
        ];
    }

    private function makeResult(array $rows): Result
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn($rows);
        return $result;
    }

    public function test_returns_zero_when_no_pending_rows(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('executeQuery')->willReturn($this->makeResult([]));

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->never())->method('send');

        $relay = $this->makeRelay($conn, $mailer);
        $this->assertSame(0, $relay->relay());
    }

    public function test_sends_pending_rows_and_returns_count(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('executeQuery')->willReturn($this->makeResult([$this->makeRow()]));
        $conn->method('executeStatement')->willReturn(1);

        $sent  = new SentEmail('msg-1', new \DateTimeImmutable(), 1, 'ses', 'us-east-1');
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturn($sent);

        $relay = $this->makeRelay($conn, $mailer);
        $this->assertSame(1, $relay->relay());
    }

    public function test_marks_row_sent_after_successful_delivery(): void
    {
        $executedSql = [];

        $conn = $this->createMock(Connection::class);
        $conn->method('executeQuery')->willReturn($this->makeResult([$this->makeRow()]));
        $conn->method('executeStatement')->willReturnCallback(function ($sql) use (&$executedSql) {
            $executedSql[] = $sql;
            return 1;
        });

        $sent   = new SentEmail('msg-1', new \DateTimeImmutable(), 1, 'ses', 'us-east-1');
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturn($sent);

        $this->makeRelay($conn, $mailer)->relay();

        $this->assertStringContainsString("status = 'sent'", $executedSql[0]);
    }

    public function test_marks_row_dead_after_max_attempts(): void
    {
        $executedSql = [];

        $conn = $this->createMock(Connection::class);
        // Row already has attempt_count=2, maxDeliveryAttempts=3 → this is the 3rd attempt → dead
        $conn->method('executeQuery')->willReturn($this->makeResult([$this->makeRow(attemptCount: 2)]));
        $conn->method('executeStatement')->willReturnCallback(function ($sql) use (&$executedSql) {
            $executedSql[] = $sql;
            return 1;
        });

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willThrowException(new MailSendException('permanent failure'));

        $this->makeRelay($conn, $mailer)->relay();

        $this->assertStringContainsString("status = 'dead'", $executedSql[0]);
    }

    public function test_marks_row_pending_with_backoff_on_transient_failure(): void
    {
        $executedSql = [];

        $conn = $this->createMock(Connection::class);
        $conn->method('executeQuery')->willReturn($this->makeResult([$this->makeRow(attemptCount: 0)]));
        $conn->method('executeStatement')->willReturnCallback(function ($sql) use (&$executedSql) {
            $executedSql[] = $sql;
            return 1;
        });

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willThrowException(new MailSendException('transient'));

        $this->makeRelay($conn, $mailer)->relay();

        $this->assertStringContainsString("status = 'pending'", $executedSql[0]);
        $this->assertStringContainsString('next_attempt_at', $executedSql[0]);
    }

    public function test_injects_idempotency_key_into_email(): void
    {
        $capturedEmail = null;

        $conn = $this->createMock(Connection::class);
        $conn->method('executeQuery')->willReturn($this->makeResult([$this->makeRow('my-outbox-id')]));
        $conn->method('executeStatement')->willReturn(1);

        $sent   = new SentEmail('msg-1', new \DateTimeImmutable(), 1, 'ses', 'us-east-1');
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (Email $email) use ($sent, &$capturedEmail) {
            $capturedEmail = $email;
            return $sent;
        });

        $this->makeRelay($conn, $mailer)->relay();

        $this->assertSame('my-outbox-id', $capturedEmail?->getMeta('idempotency_key'));
    }

    public function test_failed_row_does_not_affect_count(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('executeQuery')->willReturn($this->makeResult([$this->makeRow()]));
        $conn->method('executeStatement')->willReturn(1);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willThrowException(new MailSendException('fail'));

        $relay = $this->makeRelay($conn, $mailer);
        $this->assertSame(0, $relay->relay());
    }
}
