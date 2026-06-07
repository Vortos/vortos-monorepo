<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Tests\Middleware;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Vortos\AwsSes\Contract\EmailSendObserverInterface;
use Vortos\AwsSes\Middleware\HookMiddleware;
use Vortos\AwsSes\ValueObject\Email;
use Vortos\AwsSes\ValueObject\SentEmail;

final class HookMiddlewareTest extends TestCase
{
    private function makeEmail(): Email
    {
        return Email::new()->to('u@example.com')->subject('S')->htmlBody('H');
    }

    private function makeSentEmail(): SentEmail
    {
        return new SentEmail('msg-1', new \DateTimeImmutable(), 1, 'ses', 'us-east-1');
    }

    public function test_passes_through_when_no_observers(): void
    {
        $mw   = new HookMiddleware([], new NullLogger());
        $sent = $this->makeSentEmail();

        $result = $mw->process($this->makeEmail(), fn($e) => $sent);

        $this->assertSame($sent, $result);
    }

    public function test_fires_before_send_on_each_observer(): void
    {
        $called = 0;
        $observer = new class($called) implements EmailSendObserverInterface {
            public function __construct(public int &$called) {}
            public function beforeSend(Email $email): void    { ++$this->called; }
            public function afterSend(Email $email, SentEmail $r): void {}
            public function onSendError(Email $email, \Throwable $e): void {}
        };

        $mw = new HookMiddleware([$observer, $observer], new NullLogger());
        $mw->process($this->makeEmail(), fn($e) => $this->makeSentEmail());

        $this->assertSame(2, $called);
    }

    public function test_fires_after_send_on_success(): void
    {
        $afterCalled = false;
        $observer = new class($afterCalled) implements EmailSendObserverInterface {
            public function __construct(public bool &$afterCalled) {}
            public function beforeSend(Email $email): void {}
            public function afterSend(Email $email, SentEmail $r): void { $this->afterCalled = true; }
            public function onSendError(Email $email, \Throwable $e): void {}
        };

        $mw = new HookMiddleware([$observer], new NullLogger());
        $mw->process($this->makeEmail(), fn($e) => $this->makeSentEmail());

        $this->assertTrue($afterCalled);
    }

    public function test_fires_on_send_error_and_rethrows(): void
    {
        $errorCalled = false;
        $observer = new class($errorCalled) implements EmailSendObserverInterface {
            public function __construct(public bool &$errorCalled) {}
            public function beforeSend(Email $email): void {}
            public function afterSend(Email $email, SentEmail $r): void {}
            public function onSendError(Email $email, \Throwable $e): void { $this->errorCalled = true; }
        };

        $mw = new HookMiddleware([$observer], new NullLogger());

        try {
            $mw->process($this->makeEmail(), fn($e) => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {}

        $this->assertTrue($errorCalled);
    }

    public function test_exception_propagates_after_error_hook(): void
    {
        $mw = new HookMiddleware([], new NullLogger());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $mw->process($this->makeEmail(), fn($e) => throw new \RuntimeException('boom'));
    }

    public function test_failing_observer_does_not_break_delivery(): void
    {
        $observer = new class implements EmailSendObserverInterface {
            public function beforeSend(Email $email): void    { throw new \RuntimeException('observer error'); }
            public function afterSend(Email $email, SentEmail $r): void {}
            public function onSendError(Email $email, \Throwable $e): void {}
        };

        $sent = $this->makeSentEmail();
        $mw   = new HookMiddleware([$observer], new NullLogger());

        $result = $mw->process($this->makeEmail(), fn($e) => $sent);

        $this->assertSame($sent, $result);
    }

    public function test_after_send_not_called_when_next_throws(): void
    {
        $afterCalled = false;
        $observer = new class($afterCalled) implements EmailSendObserverInterface {
            public function __construct(public bool &$afterCalled) {}
            public function beforeSend(Email $email): void {}
            public function afterSend(Email $email, SentEmail $r): void { $this->afterCalled = true; }
            public function onSendError(Email $email, \Throwable $e): void {}
        };

        $mw = new HookMiddleware([$observer], new NullLogger());

        try {
            $mw->process($this->makeEmail(), fn($e) => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {}

        $this->assertFalse($afterCalled);
    }
}
