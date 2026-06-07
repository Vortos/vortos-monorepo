<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Tests\Middleware;

use PHPUnit\Framework\TestCase;
use Vortos\AwsSes\Contract\EmailMiddlewareInterface;
use Vortos\AwsSes\Contract\MailerInterface;
use Vortos\AwsSes\Middleware\EmailMiddlewareStack;
use Vortos\AwsSes\ValueObject\Email;
use Vortos\AwsSes\ValueObject\SentEmail;

final class EmailMiddlewareStackTest extends TestCase
{
    private function makeSentEmail(): SentEmail
    {
        return new SentEmail('msg-1', new \DateTimeImmutable(), 1, 'log', null);
    }

    private function makeEmail(): Email
    {
        return Email::new()->to('u@example.com')->subject('S')->htmlBody('H');
    }

    private function makeDriver(SentEmail $result): MailerInterface
    {
        return new class($result) implements MailerInterface {
            public function __construct(private SentEmail $result) {}
            public function send(Email $email): SentEmail { return $this->result; }
        };
    }

    public function test_calls_driver_when_no_middleware(): void
    {
        $sent   = $this->makeSentEmail();
        $driver = $this->makeDriver($sent);
        $stack  = new EmailMiddlewareStack($driver, []);

        $result = $stack->send($this->makeEmail());

        $this->assertSame($sent, $result);
    }

    public function test_single_middleware_wraps_driver(): void
    {
        $log    = [];
        $sent   = $this->makeSentEmail();
        $driver = $this->makeDriver($sent);

        $mw = new class($log) implements EmailMiddlewareInterface {
            public function __construct(public array &$log) {}
            public function process(Email $email, callable $next): SentEmail
            {
                $this->log[] = 'before';
                $result = $next($email);
                $this->log[] = 'after';
                return $result;
            }
        };

        $stack  = new EmailMiddlewareStack($driver, [$mw]);
        $result = $stack->send($this->makeEmail());

        $this->assertSame($sent, $result);
        $this->assertSame(['before', 'after'], $log);
    }

    public function test_middleware_runs_in_priority_order(): void
    {
        $order  = [];
        $sent   = $this->makeSentEmail();
        $driver = $this->makeDriver($sent);

        $makeMiddleware = function (string $name) use (&$order): EmailMiddlewareInterface {
            return new class($name, $order) implements EmailMiddlewareInterface {
                public function __construct(private string $name, public array &$order) {}
                public function process(Email $email, callable $next): SentEmail
                {
                    $this->order[] = $this->name;
                    return $next($email);
                }
            };
        };

        // Passed already sorted highest-first (stack does NOT re-sort — that is the compiler pass's job)
        $stack = new EmailMiddlewareStack($driver, [
            $makeMiddleware('first'),
            $makeMiddleware('second'),
            $makeMiddleware('third'),
        ]);

        $stack->send($this->makeEmail());

        $this->assertSame(['first', 'second', 'third'], $order);
    }

    public function test_middleware_can_short_circuit(): void
    {
        $driverCalled = false;
        $driver = new class($driverCalled) implements MailerInterface {
            public function __construct(public bool &$called) {}
            public function send(Email $email): SentEmail
            {
                $this->called = true;
                return new SentEmail('id', new \DateTimeImmutable(), 1, 'null', null);
            }
        };

        $shortCircuitResult = new SentEmail('short', new \DateTimeImmutable(), 1, 'null', null);
        $mw = new class($shortCircuitResult) implements EmailMiddlewareInterface {
            public function __construct(private SentEmail $result) {}
            public function process(Email $email, callable $next): SentEmail
            {
                return $this->result;
            }
        };

        $stack  = new EmailMiddlewareStack($driver, [$mw]);
        $result = $stack->send($this->makeEmail());

        $this->assertSame($shortCircuitResult, $result);
        $this->assertFalse($driverCalled);
    }

    public function test_exception_propagates_through_stack(): void
    {
        $driver = new class implements MailerInterface {
            public function send(Email $email): SentEmail
            {
                throw new \RuntimeException('driver error');
            }
        };

        $mw = new class implements EmailMiddlewareInterface {
            public function process(Email $email, callable $next): SentEmail
            {
                return $next($email);
            }
        };

        $stack = new EmailMiddlewareStack($driver, [$mw]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('driver error');
        $stack->send($this->makeEmail());
    }

    public function test_multiple_middlewares_each_wrap_next(): void
    {
        $log    = [];
        $sent   = $this->makeSentEmail();
        $driver = $this->makeDriver($sent);

        $makeMiddleware = function (string $label) use (&$log): EmailMiddlewareInterface {
            return new class($label, $log) implements EmailMiddlewareInterface {
                public function __construct(private string $label, public array &$log) {}
                public function process(Email $email, callable $next): SentEmail
                {
                    $this->log[] = "enter:{$this->label}";
                    $r = $next($email);
                    $this->log[] = "exit:{$this->label}";
                    return $r;
                }
            };
        };

        $stack = new EmailMiddlewareStack($driver, [
            $makeMiddleware('A'),
            $makeMiddleware('B'),
        ]);

        $stack->send($this->makeEmail());

        $this->assertSame(['enter:A', 'enter:B', 'exit:B', 'exit:A'], $log);
    }
}
