<?php

declare(strict_types=1);

namespace Vortos\Tests\Messaging\Dev;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Domain\Event\EventEnvelope;
use Vortos\Domain\Event\Metadata;
use Vortos\Messaging\Dev\Channel\TailChannelInterface;
use Vortos\Messaging\Dev\Hook\ConsumerTailAfterHandlerHook;
use Vortos\Messaging\Dev\TailState;
use Vortos\Messaging\Hook\HandlerOutcome;

final class ConsumerTailAfterHandlerHookTest extends TestCase
{
    private function makeEnvelope(string $eventId = 'evt-001'): EventEnvelope
    {
        return new EventEnvelope(
            eventId:          $eventId,
            aggregateId:      'agg-001',
            aggregateType:    'User',
            aggregateVersion: 1,
            payloadType:      'App\\Domain\\User\\UserRegistered',
            schemaVersion:    1,
            occurredAt:       new DateTimeImmutable(),
            payload:          new \stdClass(),
            metadata:         new Metadata(correlationId: 'corr-111'),
        );
    }

    private function makeChannel(): TailChannelInterface&\PHPUnit\Framework\MockObject\MockObject
    {
        return $this->createMock(TailChannelInterface::class);
    }

    public function test_does_nothing_when_inactive(): void
    {
        $channel = $this->makeChannel();
        $channel->expects($this->never())->method('handlerResult');
        $channel->expects($this->never())->method('handlerRetry');

        $state = new TailState();
        $hook  = new ConsumerTailAfterHandlerHook($state);
        $hook->__invoke($this->makeEnvelope(), 'my-consumer', 'h1', HandlerOutcome::Succeeded, 1, 10.0);

        $this->assertFalse($state->isActive());
    }

    public function test_calls_handler_result_when_active_and_succeeded(): void
    {
        $channel = $this->makeChannel();
        $channel->expects($this->once())->method('handlerResult');
        $channel->expects($this->never())->method('handlerRetry');

        $state = new TailState();
        $state->activate($channel);

        $hook = new ConsumerTailAfterHandlerHook($state);
        $hook->__invoke($this->makeEnvelope(), 'my-consumer', 'MyHandler::handle', HandlerOutcome::Succeeded, 1, 14.5);
    }

    public function test_routes_attempt_failed_to_handler_retry(): void
    {
        $channel = $this->makeChannel();
        $channel->expects($this->never())->method('handlerResult');
        $channel->expects($this->once())->method('handlerRetry')
            ->with('evt-001', 'MyHandler::handle', 2, $this->anything(), 'SMTP timeout');

        $state = new TailState();
        $state->activate($channel);

        $hook = new ConsumerTailAfterHandlerHook($state);
        $hook->__invoke(
            $this->makeEnvelope(),
            'my-consumer',
            'MyHandler::handle',
            HandlerOutcome::AttemptFailed,
            2,
            300.0,
            new \RuntimeException('SMTP timeout'),
        );
    }

    public function test_passes_outcome_and_attempts_to_handler_result(): void
    {
        $received = [];
        $channel  = new class($received) implements TailChannelInterface {
            public function __construct(private array &$received) {}
            public function messageStart(string $eventId, string $time, string $eventShort, string $aggId, string $corr): void {}
            public function handlerRetry(string $eventId, string $handlerId, int $attempt, float $latencyMs, string $error): void {}
            public function handlerResult(string $eventId, string $handlerId, HandlerOutcome $outcome, int $attempts, float $latencyMs, ?\Throwable $throwable): void
            {
                $this->received = [$eventId, $handlerId, $outcome, $attempts, $latencyMs, $throwable];
            }
            public function eventFlush(): void {}
            public function streamEnd(): void {}
        };

        $state = new TailState();
        $state->activate($channel);

        $ex   = new \RuntimeException('Connection refused');
        $hook = new ConsumerTailAfterHandlerHook($state);
        $hook->__invoke($this->makeEnvelope('evt-42'), 'my-consumer', 'MyHandler::handle', HandlerOutcome::DeadLettered, 3, 55.2, $ex);

        $this->assertSame('evt-42', $received[0]);
        $this->assertSame('MyHandler::handle', $received[1]);
        $this->assertSame(HandlerOutcome::DeadLettered, $received[2]);
        $this->assertSame(3, $received[3]);
        $this->assertEqualsWithDelta(55.2, $received[4], 0.01);
        $this->assertSame($ex, $received[5]);
    }

    public function test_passes_skipped_idempotent_to_handler_result(): void
    {
        $received = null;
        $channel  = new class($received) implements TailChannelInterface {
            public function __construct(private mixed &$received) {}
            public function messageStart(string $eventId, string $time, string $eventShort, string $aggId, string $corr): void {}
            public function handlerRetry(string $eventId, string $handlerId, int $attempt, float $latencyMs, string $error): void {}
            public function handlerResult(string $eventId, string $handlerId, HandlerOutcome $outcome, int $attempts, float $latencyMs, ?\Throwable $throwable): void
            {
                $this->received = $outcome;
            }
            public function eventFlush(): void {}
            public function streamEnd(): void {}
        };

        $state = new TailState();
        $state->activate($channel);

        $hook = new ConsumerTailAfterHandlerHook($state);
        $hook->__invoke($this->makeEnvelope(), 'my-consumer', 'MyHandler::handle', HandlerOutcome::SkippedIdempotent, 1, 0.0);

        $this->assertSame(HandlerOutcome::SkippedIdempotent, $received);
    }

    public function test_null_throwable_is_passed_through_for_terminal_outcomes(): void
    {
        $received = 'not-null';
        $channel  = new class($received) implements TailChannelInterface {
            public function __construct(private mixed &$received) {}
            public function messageStart(string $eventId, string $time, string $eventShort, string $aggId, string $corr): void {}
            public function handlerRetry(string $eventId, string $handlerId, int $attempt, float $latencyMs, string $error): void {}
            public function handlerResult(string $eventId, string $handlerId, HandlerOutcome $outcome, int $attempts, float $latencyMs, ?\Throwable $throwable): void
            {
                $this->received = $throwable;
            }
            public function eventFlush(): void {}
            public function streamEnd(): void {}
        };

        $state = new TailState();
        $state->activate($channel);

        $hook = new ConsumerTailAfterHandlerHook($state);
        $hook->__invoke($this->makeEnvelope(), 'my-consumer', 'MyHandler::handle', HandlerOutcome::Succeeded, 1, 10.0);

        $this->assertNull($received);
    }
}
