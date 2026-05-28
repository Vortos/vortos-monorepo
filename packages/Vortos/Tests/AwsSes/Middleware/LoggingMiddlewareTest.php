<?php

declare(strict_types=1);

namespace Vortos\Tests\AwsSes\Middleware;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Vortos\AwsSes\Middleware\LoggingMiddleware;
use Vortos\AwsSes\ValueObject\Email;
use Vortos\AwsSes\ValueObject\SentEmail;

final class LoggingMiddlewareTest extends TestCase
{
    /** @var array<array{level: string, message: string, context: array}> */
    private array $logs = [];

    private function makeLogger(): LoggerInterface
    {
        $logs         = &$this->logs;
        return new class($logs) implements LoggerInterface {
            public function __construct(private array &$logs) {}
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->logs[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
            public function emergency(string|\Stringable $m, array $c = []): void { $this->log('emergency', $m, $c); }
            public function alert(string|\Stringable $m, array $c = []): void     { $this->log('alert', $m, $c); }
            public function critical(string|\Stringable $m, array $c = []): void  { $this->log('critical', $m, $c); }
            public function error(string|\Stringable $m, array $c = []): void     { $this->log('error', $m, $c); }
            public function warning(string|\Stringable $m, array $c = []): void   { $this->log('warning', $m, $c); }
            public function notice(string|\Stringable $m, array $c = []): void    { $this->log('notice', $m, $c); }
            public function info(string|\Stringable $m, array $c = []): void      { $this->log('info', $m, $c); }
            public function debug(string|\Stringable $m, array $c = []): void     { $this->log('debug', $m, $c); }
        };
    }

    private function makeSentEmail(): SentEmail
    {
        return new SentEmail('ses-msg-1', new \DateTimeImmutable(), 1, 'ses', 'us-east-1');
    }

    public function test_logs_info_on_success(): void
    {
        $mw    = new LoggingMiddleware($this->makeLogger());
        $email = Email::new()->to('u@example.com')->subject('Hi')->htmlBody('H');
        $sent  = $this->makeSentEmail();

        $mw->process($email, fn($e) => $sent);

        $this->assertCount(1, $this->logs);
        $this->assertSame('info', $this->logs[0]['level']);
        $this->assertSame('ses.mailer: email sent', $this->logs[0]['message']);
    }

    public function test_success_log_contains_message_id(): void
    {
        $mw    = new LoggingMiddleware($this->makeLogger());
        $email = Email::new()->to('u@example.com')->subject('S')->htmlBody('H');
        $sent  = $this->makeSentEmail();

        $mw->process($email, fn($e) => $sent);

        $this->assertSame('ses-msg-1', $this->logs[0]['context']['message_id']);
    }

    public function test_success_log_contains_driver(): void
    {
        $mw    = new LoggingMiddleware($this->makeLogger());
        $email = Email::new()->to('u@example.com')->subject('S')->htmlBody('H');
        $sent  = $this->makeSentEmail();

        $mw->process($email, fn($e) => $sent);

        $this->assertSame('ses', $this->logs[0]['context']['driver']);
    }

    public function test_success_log_contains_latency_ms(): void
    {
        $mw    = new LoggingMiddleware($this->makeLogger());
        $email = Email::new()->to('u@example.com')->subject('S')->htmlBody('H');

        $mw->process($email, fn($e) => $this->makeSentEmail());

        $this->assertArrayHasKey('latency_ms', $this->logs[0]['context']);
        $this->assertIsFloat($this->logs[0]['context']['latency_ms']);
    }

    public function test_logs_error_and_rethrows_on_failure(): void
    {
        $mw    = new LoggingMiddleware($this->makeLogger());
        $email = Email::new()->to('u@example.com')->subject('S')->htmlBody('H');

        try {
            $mw->process($email, fn($e) => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {}

        $this->assertCount(1, $this->logs);
        $this->assertSame('error', $this->logs[0]['level']);
        $this->assertSame('ses.mailer: email send failed', $this->logs[0]['message']);
    }

    public function test_exception_propagates_after_logging(): void
    {
        $mw    = new LoggingMiddleware($this->makeLogger());
        $email = Email::new()->to('u@example.com')->subject('S')->htmlBody('H');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $mw->process($email, fn($e) => throw new \RuntimeException('boom'));
    }

    public function test_error_log_contains_error_message(): void
    {
        $mw    = new LoggingMiddleware($this->makeLogger());
        $email = Email::new()->to('u@example.com')->subject('S')->htmlBody('H');

        try {
            $mw->process($email, fn($e) => throw new \RuntimeException('something broke'));
        } catch (\RuntimeException) {}

        $this->assertSame('something broke', $this->logs[0]['context']['error']);
    }
}
