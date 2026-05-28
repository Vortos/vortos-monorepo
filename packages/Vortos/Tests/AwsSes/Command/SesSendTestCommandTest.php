<?php

declare(strict_types=1);

namespace Vortos\Tests\AwsSes\Command;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\AwsSes\Command\SesSendTestCommand;
use Vortos\AwsSes\Contract\MailerInterface;
use Vortos\AwsSes\ValueObject\Email;
use Vortos\AwsSes\ValueObject\SentEmail;

final class SesSendTestCommandTest extends TestCase
{
    private function sentEmail(string $id = 'msg-test'): SentEmail
    {
        return new SentEmail($id, new DateTimeImmutable(), 1, 'ses', 'us-east-1');
    }

    private function succeedingMailer(SentEmail $sent): MailerInterface
    {
        return new class($sent) implements MailerInterface {
            public function __construct(private readonly SentEmail $sent) {}
            public function send(Email $email): SentEmail { return $this->sent; }
        };
    }

    private function failingMailer(): MailerInterface
    {
        return new class implements MailerInterface {
            public function send(Email $email): SentEmail { throw new \RuntimeException('SMTP error'); }
        };
    }

    public function test_success_outputs_message_id(): void
    {
        $command = new SesSendTestCommand($this->succeedingMailer($this->sentEmail()), 'noreply@example.com');
        $tester  = new CommandTester($command);
        $tester->execute(['to' => 'you@example.com']);

        $this->assertStringContainsString('msg-test', $tester->getDisplay());
        $this->assertSame(0, $tester->getStatusCode());
    }

    public function test_failure_returns_nonzero_exit_code(): void
    {
        $command = new SesSendTestCommand($this->failingMailer(), 'noreply@example.com');
        $tester  = new CommandTester($command);
        $tester->execute(['to' => 'you@example.com']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('SMTP error', $tester->getDisplay());
    }

    public function test_missing_from_address_returns_failure(): void
    {
        $command = new SesSendTestCommand($this->succeedingMailer($this->sentEmail()), '');
        $tester  = new CommandTester($command);
        $tester->execute(['to' => 'you@example.com']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('from address', $tester->getDisplay());
    }

    public function test_from_option_overrides_default(): void
    {
        $captured = null;
        $mailer   = new class($captured) implements MailerInterface {
            public function __construct(public mixed &$captured) {}
            public function send(Email $email): SentEmail {
                $this->captured = $email->getFrom()->address();
                return new SentEmail('x', new DateTimeImmutable(), 1);
            }
        };

        $command = new SesSendTestCommand($mailer, 'default@example.com');
        $tester  = new CommandTester($command);
        $tester->execute(['to' => 'you@example.com', '--from' => 'custom@example.com']);

        $this->assertSame('custom@example.com', $captured);
    }
}
