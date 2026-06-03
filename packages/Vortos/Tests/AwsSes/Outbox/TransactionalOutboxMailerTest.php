<?php

declare(strict_types=1);

namespace Vortos\Tests\AwsSes\Outbox;

use PHPUnit\Framework\TestCase;
use Vortos\AwsSes\Contract\EmailOutboxWriterInterface;
use Vortos\AwsSes\Outbox\TransactionalOutboxMailer;
use Vortos\AwsSes\ValueObject\Email;

final class TransactionalOutboxMailerTest extends TestCase
{
    private function makeWriter(?callable $onQueue = null, string $returnId = 'a0000000-0000-7000-8000-000000000001'): EmailOutboxWriterInterface
    {
        return new class($onQueue, $returnId) implements EmailOutboxWriterInterface {
            public ?Email $received = null;
            public function __construct(private readonly mixed $onQueue, private readonly string $returnId) {}
            public function queue(Email $email, ?string $domainEventId = null): string
            {
                $this->received = $email;
                if ($this->onQueue) {
                    ($this->onQueue)($email);
                }
                return $this->returnId;
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

    public function test_send_returns_outbox_row_id_as_message_id(): void
    {
        $outboxId = 'a0000000-0000-7000-8000-000000000099';
        $mailer   = new TransactionalOutboxMailer($this->makeWriter(returnId: $outboxId));
        $result   = $mailer->send($this->makeEmail());

        $this->assertSame($outboxId, $result->messageId());
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
        $ids    = ['id-1', 'id-2'];
        $cursor = 0;

        $writer = new class($ids, $cursor) implements EmailOutboxWriterInterface {
            public function __construct(private readonly array $ids, private int &$cursor) {}
            public function queue(Email $email, ?string $domainEventId = null): string
            {
                return $this->ids[$this->cursor++];
            }
        };

        $mailer = new TransactionalOutboxMailer($writer);
        $email  = $this->makeEmail();

        $this->assertNotSame($mailer->send($email)->messageId(), $mailer->send($email)->messageId());
    }

    public function test_send_returns_queued_sent_email(): void
    {
        $mailer = new TransactionalOutboxMailer($this->makeWriter());
        $result = $mailer->send($this->makeEmail());

        $this->assertTrue($result->isQueued());
    }

    public function test_send_propagates_writer_exception(): void
    {
        $writer = new class implements EmailOutboxWriterInterface {
            public function queue(Email $email, ?string $domainEventId = null): string
            {
                throw new \RuntimeException('DB connection lost');
            }
        };

        $mailer = new TransactionalOutboxMailer($writer);

        $this->expectException(\RuntimeException::class);
        $mailer->send($this->makeEmail());
    }
}
