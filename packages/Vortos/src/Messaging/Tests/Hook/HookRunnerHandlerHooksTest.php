<?php

declare(strict_types=1);

namespace Vortos\Messaging\Tests\Hook;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Domain\Event\EventEnvelope;
use Vortos\Domain\Event\Metadata;
use Vortos\Messaging\Hook\HandlerOutcome;
use Vortos\Messaging\Hook\HookDescriptor;
use Vortos\Messaging\Hook\HookRegistry;
use Vortos\Messaging\Hook\HookRunner;

final class HookRunnerHandlerHooksTest extends TestCase
{
    private function makeEnvelope(string $payloadType = 'App\\Event\\SomethingHappened'): EventEnvelope
    {
        return new EventEnvelope(
            eventId:          'evt-001',
            aggregateId:      'agg-001',
            aggregateType:    'Thing',
            aggregateVersion: 1,
            payloadType:      $payloadType,
            schemaVersion:    1,
            occurredAt:       new DateTimeImmutable(),
            payload:          new \stdClass(),
            metadata:         new Metadata(),
        );
    }

    private function makeRunner(array $hooksParam, array $locatorServices): HookRunner
    {
        $registry = new HookRegistry($hooksParam);
        $locator  = new ServiceLocator(
            array_map(fn($svc) => fn() => $svc, $locatorServices),
        );

        return new HookRunner($registry, $locator, new NullLogger());
    }

    private function descriptor(string $hookType, string $serviceId, array $on = []): array
    {
        return [
            'hookType'       => $hookType,
            'serviceId'      => $serviceId,
            'eventFilter'    => null,
            'consumerFilter' => null,
            'priority'       => 0,
            'onFailureOnly'  => false,
            'on'             => $on,
        ];
    }

    public function test_run_before_handler_invokes_registered_hook(): void
    {
        $called = false;
        $hook   = new class($called) {
            public function __construct(private bool &$called) {}
            public function __invoke(EventEnvelope $e, string $consumer, string $handlerId): void
            {
                $this->called = true;
            }
        };

        $runner = $this->makeRunner(
            [HookDescriptor::BEFORE_HANDLER => [$this->descriptor(HookDescriptor::BEFORE_HANDLER, 'my_hook')]],
            ['my_hook' => $hook],
        );

        $runner->runBeforeHandler($this->makeEnvelope(), 'my-consumer', 'handler-id');

        $this->assertTrue($called);
    }

    public function test_run_after_handler_invokes_registered_hook_with_outcome(): void
    {
        $received = [];
        $hook     = new class($received) {
            public function __construct(private array &$received) {}
            public function __invoke(EventEnvelope $e, string $consumer, string $handlerId, HandlerOutcome $outcome, int $attempts, float $latencyMs, ?\Throwable $throwable): void
            {
                $this->received = [$consumer, $handlerId, $outcome, $attempts, $latencyMs, $throwable];
            }
        };

        $runner = $this->makeRunner(
            [HookDescriptor::AFTER_HANDLER => [$this->descriptor(HookDescriptor::AFTER_HANDLER, 'my_hook')]],
            ['my_hook' => $hook],
        );

        $ex = new \RuntimeException('boom');
        $runner->runAfterHandler($this->makeEnvelope(), 'my-consumer', 'h-1', HandlerOutcome::DeadLettered, 3, 12.5, $ex);

        $this->assertSame('my-consumer', $received[0]);
        $this->assertSame('h-1', $received[1]);
        $this->assertSame(HandlerOutcome::DeadLettered, $received[2]);
        $this->assertSame(3, $received[3]);
        $this->assertEqualsWithDelta(12.5, $received[4], 0.01);
        $this->assertSame($ex, $received[5]);
    }

    public function test_after_handler_default_filter_excludes_attempt_failed(): void
    {
        $called = false;
        $hook   = new class($called) {
            public function __construct(private bool &$called) {}
            public function __invoke(EventEnvelope $e, string $c, string $h, HandlerOutcome $outcome, int $attempts, float $ms, ?\Throwable $t): void
            {
                $this->called = true;
            }
        };

        $runner = $this->makeRunner(
            [HookDescriptor::AFTER_HANDLER => [$this->descriptor(HookDescriptor::AFTER_HANDLER, 'my_hook', [])]],
            ['my_hook' => $hook],
        );

        $runner->runAfterHandler($this->makeEnvelope(), 'my-consumer', 'h-1', HandlerOutcome::AttemptFailed, 1, 10.0, new \RuntimeException('err'));

        $this->assertFalse($called, 'AttemptFailed must not fire when on is empty (default)');
    }

    public function test_after_handler_default_filter_fires_for_terminal_outcomes(): void
    {
        $outcomes = [];
        $hook     = new class($outcomes) {
            public function __construct(private array &$outcomes) {}
            public function __invoke(EventEnvelope $e, string $c, string $h, HandlerOutcome $outcome, int $attempts, float $ms, ?\Throwable $t): void
            {
                $this->outcomes[] = $outcome;
            }
        };

        $runner = $this->makeRunner(
            [HookDescriptor::AFTER_HANDLER => [$this->descriptor(HookDescriptor::AFTER_HANDLER, 'my_hook', [])]],
            ['my_hook' => $hook],
        );

        $runner->runAfterHandler($this->makeEnvelope(), 'c', 'h', HandlerOutcome::Succeeded, 1, 10.0);
        $runner->runAfterHandler($this->makeEnvelope(), 'c', 'h', HandlerOutcome::SucceededAfterRetries, 2, 20.0);
        $runner->runAfterHandler($this->makeEnvelope(), 'c', 'h', HandlerOutcome::SkippedIdempotent, 1, 0.0);
        $runner->runAfterHandler($this->makeEnvelope(), 'c', 'h', HandlerOutcome::DiscardedReplayLimit, 1, 0.0);
        $runner->runAfterHandler($this->makeEnvelope(), 'c', 'h', HandlerOutcome::DeadLettered, 3, 30.0, new \RuntimeException('x'));

        $this->assertCount(5, $outcomes);
    }

    public function test_after_handler_fires_attempt_failed_when_explicitly_listed_in_on(): void
    {
        $called = false;
        $hook   = new class($called) {
            public function __construct(private bool &$called) {}
            public function __invoke(EventEnvelope $e, string $c, string $h, HandlerOutcome $outcome, int $attempts, float $ms, ?\Throwable $t): void
            {
                $this->called = true;
            }
        };

        $runner = $this->makeRunner(
            [HookDescriptor::AFTER_HANDLER => [$this->descriptor(HookDescriptor::AFTER_HANDLER, 'my_hook', [HandlerOutcome::AttemptFailed])]],
            ['my_hook' => $hook],
        );

        $runner->runAfterHandler($this->makeEnvelope(), 'my-consumer', 'h-1', HandlerOutcome::AttemptFailed, 1, 10.0, new \RuntimeException('err'));

        $this->assertTrue($called);
    }

    public function test_after_handler_on_filter_skips_non_listed_outcomes(): void
    {
        $called = false;
        $hook   = new class($called) {
            public function __construct(private bool &$called) {}
            public function __invoke(EventEnvelope $e, string $c, string $h, HandlerOutcome $outcome, int $attempts, float $ms, ?\Throwable $t): void
            {
                $this->called = true;
            }
        };

        $runner = $this->makeRunner(
            [HookDescriptor::AFTER_HANDLER => [$this->descriptor(HookDescriptor::AFTER_HANDLER, 'my_hook', [HandlerOutcome::DeadLettered])]],
            ['my_hook' => $hook],
        );

        $runner->runAfterHandler($this->makeEnvelope(), 'my-consumer', 'h-1', HandlerOutcome::Succeeded, 1, 10.0);

        $this->assertFalse($called, 'Hook with on:[DeadLettered] must not fire for Succeeded outcome');
    }

    public function test_after_handler_terminal_success_constant_fires_only_for_success(): void
    {
        $outcomes = [];
        $hook     = new class($outcomes) {
            public function __construct(private array &$outcomes) {}
            public function __invoke(EventEnvelope $e, string $c, string $h, HandlerOutcome $outcome, int $attempts, float $ms, ?\Throwable $t): void
            {
                $this->outcomes[] = $outcome;
            }
        };

        $runner = $this->makeRunner(
            [HookDescriptor::AFTER_HANDLER => [$this->descriptor(HookDescriptor::AFTER_HANDLER, 'my_hook', HandlerOutcome::TERMINAL_SUCCESS)]],
            ['my_hook' => $hook],
        );

        $runner->runAfterHandler($this->makeEnvelope(), 'c', 'h', HandlerOutcome::Succeeded, 1, 10.0);
        $runner->runAfterHandler($this->makeEnvelope(), 'c', 'h', HandlerOutcome::SucceededAfterRetries, 2, 20.0);
        $runner->runAfterHandler($this->makeEnvelope(), 'c', 'h', HandlerOutcome::DeadLettered, 3, 30.0, new \RuntimeException('x'));
        $runner->runAfterHandler($this->makeEnvelope(), 'c', 'h', HandlerOutcome::SkippedIdempotent, 1, 0.0);
        $runner->runAfterHandler($this->makeEnvelope(), 'c', 'h', HandlerOutcome::AttemptFailed, 1, 5.0, new \RuntimeException('x'));

        $this->assertCount(2, $outcomes);
        $this->assertSame(HandlerOutcome::Succeeded, $outcomes[0]);
        $this->assertSame(HandlerOutcome::SucceededAfterRetries, $outcomes[1]);
    }

    public function test_run_before_handler_respects_consumer_filter(): void
    {
        $called = false;
        $hook   = new class($called) {
            public function __construct(private bool &$called) {}
            public function __invoke(EventEnvelope $e, string $c, string $h): void
            {
                $this->called = true;
            }
        };

        $descriptor = array_merge($this->descriptor(HookDescriptor::BEFORE_HANDLER, 'my_hook'), ['consumerFilter' => 'other-consumer']);

        $runner = $this->makeRunner(
            [HookDescriptor::BEFORE_HANDLER => [$descriptor]],
            ['my_hook' => $hook],
        );

        $runner->runBeforeHandler($this->makeEnvelope(), 'my-consumer', 'handler-id');

        $this->assertFalse($called);
    }

    public function test_failing_hook_does_not_prevent_other_hooks_from_running(): void
    {
        $secondCalled = false;

        $failingHook = new class {
            public function __invoke(EventEnvelope $e, string $c, string $h): void
            {
                throw new \RuntimeException('hook failure');
            }
        };

        $goodHook = new class($secondCalled) {
            public function __construct(private bool &$secondCalled) {}
            public function __invoke(EventEnvelope $e, string $c, string $h): void
            {
                $this->secondCalled = true;
            }
        };

        $runner = $this->makeRunner(
            [HookDescriptor::BEFORE_HANDLER => [
                array_merge($this->descriptor(HookDescriptor::BEFORE_HANDLER, 'failing'), ['priority' => 10]),
                $this->descriptor(HookDescriptor::BEFORE_HANDLER, 'good'),
            ]],
            ['failing' => $failingHook, 'good' => $goodHook],
        );

        $runner->runBeforeHandler($this->makeEnvelope(), 'my-consumer', 'h-1');

        $this->assertTrue($secondCalled);
    }
}
