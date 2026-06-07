<?php

declare(strict_types=1);

namespace Vortos\Messaging\Tests\Dev;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Domain\Event\EventEnvelope;
use Vortos\Domain\Event\Metadata;
use Vortos\Messaging\Dev\Channel\TailChannelInterface;
use Vortos\Messaging\Dev\Hook\ConsumerTailBeforeHandlerHook;
use Vortos\Messaging\Dev\TailState;

final class ConsumerTailBeforeHandlerHookTest extends TestCase
{
    private function makeEnvelope(string $eventId = 'evt-001', string $aggId = 'agg-123456789012345678901'): EventEnvelope
    {
        return new EventEnvelope(
            eventId:          $eventId,
            aggregateId:      $aggId,
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
        $channel->expects($this->never())->method('messageStart');

        $state = new TailState();
        $hook  = new ConsumerTailBeforeHandlerHook($state);
        $hook->__invoke($this->makeEnvelope(), 'my-consumer', 'handler-id');

        $this->assertFalse($state->isActive());
    }

    public function test_calls_message_start_when_active(): void
    {
        $channel = $this->makeChannel();
        $channel->expects($this->once())->method('messageStart');

        $state = new TailState();
        $state->activate($channel);

        $hook = new ConsumerTailBeforeHandlerHook($state);
        $hook->__invoke($this->makeEnvelope('evt-1'), 'my-consumer', 'handler-id');
    }

    public function test_short_payload_type_is_passed_to_channel(): void
    {
        $received = null;
        $channel  = new class($received) implements TailChannelInterface {
            public function __construct(private mixed &$received) {}
            public function messageStart(string $eventId, string $time, string $eventShort, string $aggId, string $corr): void
            {
                $this->received = $eventShort;
            }
            public function handlerRetry(string $eventId, string $handlerId, int $attempt, float $latencyMs, string $error): void {}
            public function handlerResult(string $eventId, string $handlerId, \Vortos\Messaging\Hook\HandlerOutcome $outcome, int $attempts, float $latencyMs, ?\Throwable $throwable): void {}
            public function eventFlush(): void {}
            public function streamEnd(): void {}
        };

        $state = new TailState();
        $state->activate($channel);

        $hook = new ConsumerTailBeforeHandlerHook($state);
        $hook->__invoke($this->makeEnvelope('evt-1'), 'my-consumer', 'h1');

        $this->assertSame('UserRegistered', $received);
    }

    public function test_aggregate_id_is_truncated_to_22_chars_with_ellipsis(): void
    {
        $receivedAggId = null;
        $channel       = new class($receivedAggId) implements TailChannelInterface {
            public function __construct(private mixed &$receivedAggId) {}
            public function messageStart(string $eventId, string $time, string $eventShort, string $aggId, string $corr): void
            {
                $this->receivedAggId = $aggId;
            }
            public function handlerRetry(string $eventId, string $handlerId, int $attempt, float $latencyMs, string $error): void {}
            public function handlerResult(string $eventId, string $handlerId, \Vortos\Messaging\Hook\HandlerOutcome $outcome, int $attempts, float $latencyMs, ?\Throwable $throwable): void {}
            public function eventFlush(): void {}
            public function streamEnd(): void {}
        };

        $state = new TailState();
        $state->activate($channel);

        // aggregateId has 24 chars — should be truncated to 22 + '...'
        $hook = new ConsumerTailBeforeHandlerHook($state);
        $hook->__invoke($this->makeEnvelope('evt-1', 'agg-123456789012345678901'), 'my-consumer', 'h1');

        $this->assertStringEndsWith('...', (string) $receivedAggId);
        $this->assertSame(25, strlen((string) $receivedAggId)); // 22 + 3 for '...'
    }

    public function test_same_event_id_does_not_fire_message_start_twice(): void
    {
        $channel = $this->makeChannel();
        $channel->expects($this->once())->method('messageStart');

        $state = new TailState();
        $state->activate($channel);

        $hook = new ConsumerTailBeforeHandlerHook($state);
        $hook->__invoke($this->makeEnvelope('evt-1'), 'my-consumer', 'h1');
        $hook->__invoke($this->makeEnvelope('evt-1'), 'my-consumer', 'h2');
    }

    public function test_different_event_ids_each_fire_message_start(): void
    {
        $channel = $this->makeChannel();
        $channel->expects($this->exactly(2))->method('messageStart');

        $state = new TailState();
        $state->activate($channel);

        $hook = new ConsumerTailBeforeHandlerHook($state);
        $hook->__invoke($this->makeEnvelope('evt-1'), 'my-consumer', 'h1');
        $hook->__invoke($this->makeEnvelope('evt-2'), 'my-consumer', 'h2');
    }
}
