<?php

declare(strict_types=1);

namespace Vortos\Tests\Messaging;

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

final class HookRunnerTest extends TestCase
{
    private function makeEnvelope(string $payloadType = 'App\\SomeEvent'): EventEnvelope
    {
        return new EventEnvelope(
            eventId:          'evt-001',
            aggregateId:      'agg-001',
            aggregateType:    'TestAggregate',
            aggregateVersion: 1,
            payloadType:      $payloadType,
            schemaVersion:    1,
            occurredAt:       new DateTimeImmutable('2026-01-15T10:00:00Z'),
            payload:          new \stdClass(),
            metadata:         new Metadata(correlationId: 'corr-abc'),
        );
    }

    private function makeRunner(array $hooks, array $services = []): HookRunner
    {
        $registry = new HookRegistry($hooks);
        $locator  = new ServiceLocator(
            array_map(fn($s) => fn() => $s, $services),
        );
        return new HookRunner($registry, $locator, new NullLogger());
    }

    private function hookDescriptor(string $type, string $serviceId, ?string $eventFilter = null, ?string $consumerFilter = null, bool $onFailureOnly = false, array $on = []): array
    {
        return [
            'hookType'       => $type,
            'serviceId'      => $serviceId,
            'eventFilter'    => $eventFilter,
            'consumerFilter' => $consumerFilter,
            'priority'       => 0,
            'onFailureOnly'  => $onFailureOnly,
            'on'             => $on,
        ];
    }

    // --- Producer-side hooks ---

    public function test_run_before_dispatch_calls_hook(): void
    {
        $called   = false;
        $hook     = new class($called) {
            public function __construct(private bool &$called) {}
            public function __invoke(EventEnvelope $e): void { $this->called = true; }
        };

        $runner = $this->makeRunner(
            [HookDescriptor::BEFORE_DISPATCH => [$this->hookDescriptor(HookDescriptor::BEFORE_DISPATCH, 'svc')]],
            ['svc' => $hook],
        );

        $runner->runBeforeDispatch($this->makeEnvelope());

        $this->assertTrue($called);
    }

    public function test_run_before_dispatch_passes_envelope_to_hook(): void
    {
        $received = null;
        $hook     = new class($received) {
            public function __construct(private mixed &$received) {}
            public function __invoke(EventEnvelope $e): void { $this->received = $e; }
        };

        $envelope = $this->makeEnvelope('App\\MyEvent');
        $runner   = $this->makeRunner(
            [HookDescriptor::BEFORE_DISPATCH => [$this->hookDescriptor(HookDescriptor::BEFORE_DISPATCH, 'svc')]],
            ['svc' => $hook],
        );

        $runner->runBeforeDispatch($envelope);

        $this->assertSame($envelope, $received);
    }

    public function test_run_before_dispatch_filters_by_event_type(): void
    {
        $called = false;
        $hook   = new class($called) {
            public function __construct(private bool &$called) {}
            public function __invoke(EventEnvelope $e): void { $this->called = true; }
        };

        $runner = $this->makeRunner(
            [HookDescriptor::BEFORE_DISPATCH => [$this->hookDescriptor(HookDescriptor::BEFORE_DISPATCH, 'svc', 'App\\OtherEvent')]],
            ['svc' => $hook],
        );

        $runner->runBeforeDispatch($this->makeEnvelope('App\\MyEvent'));

        $this->assertFalse($called, 'Hook with non-matching event filter must not run');
    }

    public function test_run_after_dispatch_skips_non_failure_when_on_failure_only(): void
    {
        $called = false;
        $hook   = new class($called) {
            public function __construct(private bool &$called) {}
            public function __invoke(EventEnvelope $e, ?\Throwable $t): void { $this->called = true; }
        };

        $runner = $this->makeRunner(
            [HookDescriptor::AFTER_DISPATCH => [$this->hookDescriptor(HookDescriptor::AFTER_DISPATCH, 'svc', null, null, true)]],
            ['svc' => $hook],
        );

        $runner->runAfterDispatch($this->makeEnvelope(), null); // no throwable

        $this->assertFalse($called, 'on_failure_only hook must not run when there is no exception');
    }

    public function test_run_after_dispatch_fires_on_failure_only_hook_when_throwable(): void
    {
        $called = false;
        $hook   = new class($called) {
            public function __construct(private bool &$called) {}
            public function __invoke(EventEnvelope $e, ?\Throwable $t): void { $this->called = true; }
        };

        $runner = $this->makeRunner(
            [HookDescriptor::AFTER_DISPATCH => [$this->hookDescriptor(HookDescriptor::AFTER_DISPATCH, 'svc', null, null, true)]],
            ['svc' => $hook],
        );

        $runner->runAfterDispatch($this->makeEnvelope(), new \RuntimeException('oops'));

        $this->assertTrue($called);
    }

    // --- Consumer-side hooks ---

    public function test_run_before_consume_calls_hook_with_event_envelope(): void
    {
        $received = null;
        $hook     = new class($received) {
            public function __construct(private mixed &$received) {}
            public function __invoke(EventEnvelope $e, string $consumer): void { $this->received = $e; }
        };

        $envelope = $this->makeEnvelope('App\\UserRegistered');
        $runner   = $this->makeRunner(
            [HookDescriptor::BEFORE_CONSUME => [$this->hookDescriptor(HookDescriptor::BEFORE_CONSUME, 'svc')]],
            ['svc' => $hook],
        );

        $runner->runBeforeConsume($envelope, 'my-consumer');

        $this->assertSame($envelope, $received);
    }

    public function test_run_before_consume_filters_by_payload_type(): void
    {
        $called = false;
        $hook   = new class($called) {
            public function __construct(private bool &$called) {}
            public function __invoke(EventEnvelope $e, string $c): void { $this->called = true; }
        };

        $runner = $this->makeRunner(
            [HookDescriptor::BEFORE_CONSUME => [$this->hookDescriptor(HookDescriptor::BEFORE_CONSUME, 'svc', 'App\\OtherEvent')]],
            ['svc' => $hook],
        );

        $runner->runBeforeConsume($this->makeEnvelope('App\\UserRegistered'), 'c');

        $this->assertFalse($called, 'Hook must not fire when payloadType does not match eventFilter');
    }

    public function test_run_before_consume_filters_by_consumer(): void
    {
        $called = false;
        $hook   = new class($called) {
            public function __construct(private bool &$called) {}
            public function __invoke(EventEnvelope $e, string $c): void { $this->called = true; }
        };

        $runner = $this->makeRunner(
            [HookDescriptor::BEFORE_CONSUME => [$this->hookDescriptor(HookDescriptor::BEFORE_CONSUME, 'svc', null, 'other-consumer')]],
            ['svc' => $hook],
        );

        $runner->runBeforeConsume($this->makeEnvelope(), 'my-consumer');

        $this->assertFalse($called, 'Hook must not fire when consumer does not match consumerFilter');
    }

    public function test_run_after_consume_passes_throwable_to_hook(): void
    {
        $received = null;
        $hook     = new class($received) {
            public function __construct(private mixed &$received) {}
            public function __invoke(EventEnvelope $e, string $c, ?\Throwable $t): void { $this->received = $t; }
        };

        $runner = $this->makeRunner(
            [HookDescriptor::AFTER_CONSUME => [$this->hookDescriptor(HookDescriptor::AFTER_CONSUME, 'svc')]],
            ['svc' => $hook],
        );

        $error = new \RuntimeException('handler crashed');
        $runner->runAfterConsume($this->makeEnvelope(), 'c', $error);

        $this->assertSame($error, $received);
    }

    public function test_hook_exception_is_swallowed_and_does_not_propagate(): void
    {
        $hook = new class {
            public function __invoke(EventEnvelope $e): void { throw new \RuntimeException('hook broke'); }
        };

        $runner = $this->makeRunner(
            [HookDescriptor::BEFORE_DISPATCH => [$this->hookDescriptor(HookDescriptor::BEFORE_DISPATCH, 'svc')]],
            ['svc' => $hook],
        );

        // Must not throw
        $runner->runBeforeDispatch($this->makeEnvelope());
        $this->assertTrue(true);
    }

    public function test_failing_hook_does_not_prevent_subsequent_hooks_from_running(): void
    {
        $called = false;
        $bad    = new class { public function __invoke(EventEnvelope $e): void { throw new \RuntimeException('boom'); } };
        $good   = new class($called) {
            public function __construct(private bool &$called) {}
            public function __invoke(EventEnvelope $e): void { $this->called = true; }
        };

        $runner = $this->makeRunner(
            [HookDescriptor::BEFORE_DISPATCH => [
                $this->hookDescriptor(HookDescriptor::BEFORE_DISPATCH, 'bad'),
                $this->hookDescriptor(HookDescriptor::BEFORE_DISPATCH, 'good'),
            ]],
            ['bad' => $bad, 'good' => $good],
        );

        $runner->runBeforeDispatch($this->makeEnvelope());

        $this->assertTrue($called, 'Second hook must run even when first hook throws');
    }
}
