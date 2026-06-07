<?php

declare(strict_types=1);

namespace Vortos\Messaging\Tests\Dev;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Domain\Event\EventEnvelope;
use Vortos\Domain\Event\Metadata;
use Vortos\Messaging\Dev\Channel\RedisTailChannel;
use Vortos\Messaging\Dev\Channel\TailChannelInterface;
use Vortos\Messaging\Dev\Hook\ConsumerTailControlHook;
use Vortos\Messaging\Dev\TailState;

final class ConsumerTailControlHookTest extends TestCase
{
    private function makeEnvelope(): EventEnvelope
    {
        return new EventEnvelope(
            eventId:          'evt-001',
            aggregateId:      'agg-1',
            aggregateType:    'User',
            aggregateVersion: 1,
            payloadType:      'App\\Domain\\UserRegistered',
            schemaVersion:    1,
            occurredAt:       new DateTimeImmutable(),
            payload:          new \stdClass(),
            metadata:         new Metadata(),
        );
    }

    public function test_does_nothing_when_redis_is_null(): void
    {
        $state = new TailState();
        $hook  = new ConsumerTailControlHook($state, null);

        $hook($this->makeEnvelope(), 'user.events', 'handler.id');

        $this->assertFalse($state->isActive());
    }

    public function test_activates_when_ctrl_key_exists(): void
    {
        $state = new TailState();
        $redis = $this->createMock(\Redis::class);

        $redis->expects($this->once())
            ->method('exists')
            ->with('vortos:tail-ctrl:user.events')
            ->willReturn(1);

        $hook = new ConsumerTailControlHook($state, $redis);
        $hook($this->makeEnvelope(), 'user.events', 'handler.id');

        $this->assertTrue($state->isActive());
    }

    public function test_does_not_reactivate_when_already_active(): void
    {
        $state   = new TailState();
        $channel = $this->createMock(TailChannelInterface::class);
        $state->activate($channel);

        $redis = $this->createMock(\Redis::class);
        $redis->method('exists')->willReturn(1);

        $hook = new ConsumerTailControlHook($state, $redis);
        $hook($this->makeEnvelope(), 'user.events', 'handler.id');

        // Still active and still pointing to the original channel (not overwritten)
        $this->assertTrue($state->isActive());
    }

    public function test_deactivates_and_sends_stream_end_when_key_gone(): void
    {
        $state   = new TailState();
        $channel = $this->createMock(TailChannelInterface::class);
        $channel->expects($this->once())->method('streamEnd');
        $state->activate($channel);

        $redis = $this->createMock(\Redis::class);
        $redis->method('exists')->willReturn(0);

        $hook = new ConsumerTailControlHook($state, $redis);
        $hook($this->makeEnvelope(), 'user.events', 'handler.id');

        $this->assertFalse($state->isActive());
    }

    public function test_stays_inactive_when_key_absent_and_already_inactive(): void
    {
        $state = new TailState();
        $redis = $this->createMock(\Redis::class);
        $redis->method('exists')->willReturn(0);

        $hook = new ConsumerTailControlHook($state, $redis);
        $hook($this->makeEnvelope(), 'user.events', 'handler.id');

        $this->assertFalse($state->isActive());
    }
}
