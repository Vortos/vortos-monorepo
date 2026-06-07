<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Tests\Bounce;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Vortos\AwsSes\Bounce\BounceHandlerRunner;
use Vortos\AwsSes\Contract\BounceHandlerInterface;
use Vortos\AwsSes\ValueObject\EmailAddress;
use Vortos\AwsSes\Webhook\BounceNotification;
use Vortos\AwsSes\Webhook\BounceType;

final class BounceHandlerRunnerTest extends TestCase
{
    private function makeNotification(): BounceNotification
    {
        return new BounceNotification(
            recipient:     new EmailAddress('user@example.com'),
            bounceType:    BounceType::Permanent,
            bounceSubType: 'General',
            diagnosticCode: '550 User unknown',
            timestamp:     new \DateTimeImmutable(),
        );
    }

    public function test_runs_all_handlers(): void
    {
        $called = 0;

        $h1 = new class($called) implements BounceHandlerInterface {
            public function __construct(public int &$called) {}
            public function handle(BounceNotification $n): void { ++$this->called; }
        };
        $h2 = new class($called) implements BounceHandlerInterface {
            public function __construct(public int &$called) {}
            public function handle(BounceNotification $n): void { ++$this->called; }
        };

        $runner = new BounceHandlerRunner([$h1, $h2], new NullLogger());
        $runner->run($this->makeNotification());

        $this->assertSame(2, $called);
    }

    public function test_failing_handler_does_not_block_others(): void
    {
        $secondCalled = false;

        $failing = new class implements BounceHandlerInterface {
            public function handle(BounceNotification $n): void
            {
                throw new \RuntimeException('handler error');
            }
        };

        $succeeding = new class($secondCalled) implements BounceHandlerInterface {
            public function __construct(public bool &$secondCalled) {}
            public function handle(BounceNotification $n): void { $this->secondCalled = true; }
        };

        $runner = new BounceHandlerRunner([$failing, $succeeding], new NullLogger());
        $runner->run($this->makeNotification());

        $this->assertTrue($secondCalled);
    }

    public function test_runs_with_no_handlers(): void
    {
        $runner = new BounceHandlerRunner([], new NullLogger());
        $runner->run($this->makeNotification());
        $this->assertTrue(true); // no exception
    }
}
