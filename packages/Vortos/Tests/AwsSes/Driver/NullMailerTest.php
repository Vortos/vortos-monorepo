<?php

declare(strict_types=1);

namespace Vortos\Tests\AwsSes\Driver;

use PHPUnit\Framework\TestCase;
use Vortos\AwsSes\Driver\Null\NullMailer;
use Vortos\AwsSes\ValueObject\Email;

final class NullMailerTest extends TestCase
{
    private NullMailer $mailer;

    protected function setUp(): void
    {
        $this->mailer = new NullMailer();
    }

    public function test_send_returns_sent_email(): void
    {
        $email = Email::new()->to('user@example.com')->subject('Test')->htmlBody('<p>Hi</p>');

        $result = $this->mailer->send($email);

        $this->assertNotEmpty($result->messageId());
        $this->assertStringStartsWith('null-', $result->messageId());
    }

    public function test_send_never_throws(): void
    {
        // Invalid-ish email (no validation in NullMailer) — should still not throw
        $email = Email::new()->to('user@example.com')->subject('S')->htmlBody('H');

        $result = $this->mailer->send($email);

        $this->assertSame('null', $result->driver());
    }

    public function test_recipient_count_is_correct(): void
    {
        $email = Email::new()
            ->to('a@example.com')
            ->to('b@example.com')
            ->cc('c@example.com')
            ->subject('S')
            ->htmlBody('H');

        $result = $this->mailer->send($email);

        $this->assertSame(3, $result->recipientCount());
    }

    public function test_region_is_null(): void
    {
        $email  = Email::new()->to('user@example.com')->subject('S')->htmlBody('H');
        $result = $this->mailer->send($email);
        $this->assertNull($result->region());
    }

    public function test_sent_at_is_set(): void
    {
        $email  = Email::new()->to('user@example.com')->subject('S')->htmlBody('H');
        $result = $this->mailer->send($email);
        $this->assertNotNull($result->sentAt());
    }

    public function test_each_send_returns_unique_message_id(): void
    {
        $email = Email::new()->to('user@example.com')->subject('S')->htmlBody('H');

        $result1 = $this->mailer->send($email);
        $result2 = $this->mailer->send($email);

        $this->assertNotSame($result1->messageId(), $result2->messageId());
    }
}
