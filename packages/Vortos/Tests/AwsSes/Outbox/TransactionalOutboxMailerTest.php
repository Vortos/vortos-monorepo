<?php

declare(strict_types=1);

namespace Vortos\Tests\AwsSes\Outbox;

use PHPUnit\Framework\TestCase;
use Vortos\AwsSes\Contract\EmailOutboxWriterInterface;
use Vortos\AwsSes\Outbox\TransactionalOutboxMailer;
use Vortos\AwsSes\ValueObject\Email;

final class TransactionalOutboxMailerTest extends TestCase
{
    private function makeWriter(?callable $onQueue = null): EmailOutboxWriterInterface
    {
        return new class($onQueue) implements EmailOutboxWriterInterface {
            public ?Email $received = null;
            public function __construct(private readonly mixed $onQueue) {}
            public function queue(Email $email, ?string $domainEventId = null): void
            {
                $this->received = $email;
                if ($this->onQueue) {
                    ($this->onQueue)($email);
                }
            }
        };
    }

    private function makeEmail(): Email
    {
        return Email::new()
            ->from('sender@example.com')
            ->to('recipient@example.com')
            ->subject('Test')
            ->htmlBody('<p>Hi</p>');
    }

    public function test_send_queues_email_via_writer(): void
    {
        $writer = $this->makeWriter();
        $mailer = new TransactionalOutboxMailer($writer);
        $email  = $this->makeEmail();

        $mailer->send($email);

        $this->assertSame($email, $writer->received);
    }

    public function test_send_returns_sent_email_with_outbox_driver(): void
    {
        $mailer = new TransactionalOutboxMailer($this->makeWriter());
        $result = $mailer->send($this->makeEmail());

        $this->assertSame('outbox', $result->driver());
    }

    public function test_send_returns_non_empty_placeholder_message_id(): void
    {
        $mailer = new TransactionalOutboxMailer($this->makeWriter());
        $result = $mailer->send($this->makeEmail());

        $this->assertNotEmpty($result->messageId());
        $this->assertStringStartsWith('outbox-', $result->messageId());
    }

    public function test_send_returns_null_region(): void
    {
        $mailer = new TransactionalOutboxMailer($this->makeWriter());
        $result = $mailer->send($this->makeEmail());

        $this->assertNull($result->region());
    }

    public function test_send_counts_recipients_correctly(): void
    {
        $writer = $this->makeWriter();
        $mailer = new TransactionalOutboxMailer($writer);

        $email = Email::new()
            ->from('sender@example.com')
            ->to('a@example.com')
            ->to('b@example.com')
            ->to('c@example.com')
            ->subject('Multi')
            ->htmlBody('<p>Hi</p>');

        $result = $mailer->send($email);

        $this->assertSame(3, $result->recipientCount());
    }

    public function test_each_send_produces_unique_message_id(): void
    {
        $mailer  = new TransactionalOutboxMailer($this->makeWriter());
        $email   = $this->makeEmail();

        $id1 = $mailer->send($email)->messageId();
        $id2 = $mailer->send($email)->messageId();

        $this->assertNotSame($id1, $id2);
    }

    public function test_send_propagates_writer_exception(): void
    {
        $writer = new class implements EmailOutboxWriterInterface {
            public function queue(Email $email, ?string $domainEventId = null): void
            {
                throw new \RuntimeException('DB connection lost');
            }
        };

        $mailer = new TransactionalOutboxMailer($writer);

        $this->expectException(\RuntimeException::class);
        $mailer->send($this->makeEmail());
    }
}
