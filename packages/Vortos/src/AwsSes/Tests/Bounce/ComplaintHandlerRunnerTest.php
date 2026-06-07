<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Tests\Bounce;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Vortos\AwsSes\Bounce\ComplaintHandlerRunner;
use Vortos\AwsSes\Contract\ComplaintHandlerInterface;
use Vortos\AwsSes\ValueObject\EmailAddress;
use Vortos\AwsSes\Webhook\ComplaintNotification;

final class ComplaintHandlerRunnerTest extends TestCase
{
    private function makeNotification(): ComplaintNotification
    {
        return new ComplaintNotification(
            recipient:            new EmailAddress('user@example.com'),
            complaintFeedbackType: 'abuse',
            timestamp:            new \DateTimeImmutable(),
        );
    }

    public function test_runs_all_handlers(): void
    {
        $called = 0;

        $h1 = new class($called) implements ComplaintHandlerInterface {
            public function __construct(public int &$called) {}
            public function handle(ComplaintNotification $n): void { ++$this->called; }
        };
        $h2 = new class($called) implements ComplaintHandlerInterface {
            public function __construct(public int &$called) {}
            public function handle(ComplaintNotification $n): void { ++$this->called; }
        };

        $runner = new ComplaintHandlerRunner([$h1, $h2], new NullLogger());
        $runner->run($this->makeNotification());

        $this->assertSame(2, $called);
    }

    public function test_failing_handler_does_not_block_others(): void
    {
        $secondCalled = false;

        $failing = new class implements ComplaintHandlerInterface {
            public function handle(ComplaintNotification $n): void
            {
                throw new \RuntimeException('handler error');
            }
        };

        $succeeding = new class($secondCalled) implements ComplaintHandlerInterface {
            public function __construct(public bool &$secondCalled) {}
            public function handle(ComplaintNotification $n): void { $this->secondCalled = true; }
        };

        $runner = new ComplaintHandlerRunner([$failing, $succeeding], new NullLogger());
        $runner->run($this->makeNotification());

        $this->assertTrue($secondCalled);
    }

    public function test_runs_with_no_handlers(): void
    {
        $runner = new ComplaintHandlerRunner([], new NullLogger());
        $runner->run($this->makeNotification());
        $this->assertTrue(true); // no exception
    }
}
