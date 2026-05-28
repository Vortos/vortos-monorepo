<?php

declare(strict_types=1);

namespace Vortos\Tests\AwsSes\Driver;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Vortos\AwsSes\Driver\Log\LogMailer;
use Vortos\AwsSes\ValueObject\Email;

final class LogMailerTest extends TestCase
{
    private LogMailer $mailer;
    private LoggerInterface $logger;

    /** @var array<array{level: string, message: string, context: array}> */
    private array $logs = [];

    protected function setUp(): void
    {
        $this->logs   = [];
        $logs         = &$this->logs;
        $this->logger = new class($logs) implements LoggerInterface {
            public function __construct(private array &$logs) {}

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->logs[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }

            public function emergency(string|\Stringable $message, array $context = []): void { $this->log('emergency', $message, $context); }
            public function alert(string|\Stringable $message, array $context = []): void { $this->log('alert', $message, $context); }
            public function critical(string|\Stringable $message, array $context = []): void { $this->log('critical', $message, $context); }
            public function error(string|\Stringable $message, array $context = []): void { $this->log('error', $message, $context); }
            public function warning(string|\Stringable $message, array $context = []): void { $this->log('warning', $message, $context); }
            public function notice(string|\Stringable $message, array $context = []): void { $this->log('notice', $message, $context); }
            public function info(string|\Stringable $message, array $context = []): void { $this->log('info', $message, $context); }
            public function debug(string|\Stringable $message, array $context = []): void { $this->log('debug', $message, $context); }
        };

        $this->mailer = new LogMailer($this->logger);
    }

    public function test_send_writes_info_log(): void
    {
        $email = Email::new()->to('user@example.com')->subject('Hello')->htmlBody('<p>Hi</p>');

        $this->mailer->send($email);

        $this->assertCount(1, $this->logs);
        $this->assertSame('info', $this->logs[0]['level']);
        $this->assertSame('ses.mailer.log: email captured', $this->logs[0]['message']);
    }

    public function test_log_context_contains_to_addresses(): void
    {
        $email = Email::new()
            ->to('a@example.com')
            ->to('b@example.com')
            ->subject('Hi')
            ->htmlBody('H');

        $this->mailer->send($email);

        $context = $this->logs[0]['context'];
        $this->assertContains('a@example.com', $context['to']);
        $this->assertContains('b@example.com', $context['to']);
    }

    public function test_log_context_contains_subject(): void
    {
        $email = Email::new()->to('u@example.com')->subject('Test Subject')->htmlBody('H');

        $this->mailer->send($email);

        $this->assertSame('Test Subject', $this->logs[0]['context']['subject']);
    }

    public function test_log_context_has_html_true_when_html_body_set(): void
    {
        $email = Email::new()->to('u@example.com')->subject('S')->htmlBody('<p>Hi</p>');

        $this->mailer->send($email);

        $this->assertTrue($this->logs[0]['context']['has_html']);
        $this->assertFalse($this->logs[0]['context']['has_text']);
    }

    public function test_log_context_has_text_true_when_text_body_set(): void
    {
        $email = Email::new()->to('u@example.com')->subject('S')->textBody('Plain text');

        $this->mailer->send($email);

        $this->assertFalse($this->logs[0]['context']['has_html']);
        $this->assertTrue($this->logs[0]['context']['has_text']);
    }

    public function test_log_context_driver_is_log(): void
    {
        $email = Email::new()->to('u@example.com')->subject('S')->htmlBody('H');

        $this->mailer->send($email);

        $this->assertSame('log', $this->logs[0]['context']['driver']);
    }

    public function test_log_context_attachment_count(): void
    {
        $email = Email::new()
            ->to('u@example.com')
            ->subject('S')
            ->htmlBody('H')
            ->attach(\Vortos\AwsSes\ValueObject\Attachment::fromContent('f.pdf', 'application/pdf', 'data'));

        $this->mailer->send($email);

        $this->assertSame(1, $this->logs[0]['context']['attachments']);
    }

    public function test_send_returns_log_driver_sent_email(): void
    {
        $email  = Email::new()->to('u@example.com')->subject('S')->htmlBody('H');
        $result = $this->mailer->send($email);

        $this->assertStringStartsWith('log-', $result->messageId());
        $this->assertSame('log', $result->driver());
        $this->assertNull($result->region());
    }

    public function test_each_send_produces_unique_message_id(): void
    {
        $email = Email::new()->to('u@example.com')->subject('S')->htmlBody('H');

        $r1 = $this->mailer->send($email);
        $r2 = $this->mailer->send($email);

        $this->assertNotSame($r1->messageId(), $r2->messageId());
    }
}
