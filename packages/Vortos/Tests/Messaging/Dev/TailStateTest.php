<?php

declare(strict_types=1);

namespace Vortos\Tests\Messaging\Dev;

use PHPUnit\Framework\TestCase;
use Vortos\Messaging\Dev\Channel\TailChannelInterface;
use Vortos\Messaging\Dev\TailState;
use Vortos\Messaging\Hook\HandlerOutcome;

final class TailStateTest extends TestCase
{
    private function makeChannel(): TailChannelInterface&\PHPUnit\Framework\MockObject\MockObject
    {
        return $this->createMock(TailChannelInterface::class);
    }

    public function test_inactive_by_default(): void
    {
        $this->assertFalse((new TailState())->isActive());
    }

    public function test_active_after_activate(): void
    {
        $state = new TailState();
        $state->activate($this->makeChannel());
        $this->assertTrue($state->isActive());
    }

    public function test_inactive_after_deactivate(): void
    {
        $state = new TailState();
        $state->activate($this->makeChannel());
        $state->deactivate();
        $this->assertFalse($state->isActive());
    }

    public function test_message_start_delegates_to_channel(): void
    {
        $channel = $this->makeChannel();
        $channel->expects($this->once())
            ->method('messageStart')
            ->with('evt-1', '10:00:00', 'UserRegistered', 'agg-001...', 'corr-123');

        $state = new TailState();
        $state->activate($channel);
        $state->messageStart('evt-1', '10:00:00', 'UserRegistered', 'agg-001...', 'corr-123');
    }

    public function test_message_start_deduplicates_same_event_id(): void
    {
        $channel = $this->makeChannel();
        $channel->expects($this->once())->method('messageStart');

        $state = new TailState();
        $state->activate($channel);
        $state->messageStart('evt-1', '10:00:00', 'UserRegistered', 'agg-001...', 'corr-123');
        $state->messageStart('evt-1', '10:00:00', 'UserRegistered', 'agg-001...', 'corr-123');
    }

    public function test_message_start_fires_again_for_different_event_id(): void
    {
        $channel = $this->makeChannel();
        $channel->expects($this->exactly(2))->method('messageStart');

        $state = new TailState();
        $state->activate($channel);
        $state->messageStart('evt-1', '10:00:00', 'UserRegistered', 'agg-001...', 'corr-123');
        $state->messageStart('evt-2', '10:00:01', 'OrderPlaced', 'agg-002...', 'corr-456');
    }

    public function test_message_start_does_nothing_when_inactive(): void
    {
        $channel = $this->makeChannel();
        $channel->expects($this->never())->method('messageStart');

        $state = new TailState();
        $state->messageStart('evt-1', '10:00:00', 'UserRegistered', 'agg-001...', 'corr-123');
    }

    public function test_handler_result_delegates_to_channel(): void
    {
        $ex      = new \RuntimeException('oops');
        $channel = $this->makeChannel();
        $channel->expects($this->once())
            ->method('handlerResult')
            ->with('evt-1', 'MyHandler::handle', HandlerOutcome::Succeeded, 1, 14.5, $ex);

        $state = new TailState();
        $state->activate($channel);
        $state->handlerResult('evt-1', 'MyHandler::handle', HandlerOutcome::Succeeded, 1, 14.5, $ex);
    }

    public function test_handler_result_does_nothing_when_inactive(): void
    {
        $channel = $this->makeChannel();
        $channel->expects($this->never())->method('handlerResult');

        $state = new TailState();
        $state->handlerResult('evt-1', 'MyHandler::handle', HandlerOutcome::Succeeded, 1, 14.5, null);
    }

    public function test_handler_retry_delegates_to_channel(): void
    {
        $channel = $this->makeChannel();
        $channel->expects($this->once())
            ->method('handlerRetry')
            ->with('evt-1', 'MyHandler::handle', 2, 300.0, 'SMTP timeout');

        $state = new TailState();
        $state->activate($channel);
        $state->handlerRetry('evt-1', 'MyHandler::handle', 2, 300.0, 'SMTP timeout');
    }

    public function test_handler_retry_does_nothing_when_inactive(): void
    {
        $channel = $this->makeChannel();
        $channel->expects($this->never())->method('handlerRetry');

        $state = new TailState();
        $state->handlerRetry('evt-1', 'MyHandler::handle', 1, 100.0, 'error');
    }

    public function test_stream_end_delegates_to_channel(): void
    {
        $channel = $this->makeChannel();
        $channel->expects($this->once())->method('streamEnd');

        $state = new TailState();
        $state->activate($channel);
        $state->streamEnd();
    }

    public function test_deactivate_resets_event_id_tracking(): void
    {
        $channel = $this->makeChannel();
        $channel->expects($this->exactly(2))->method('messageStart');

        $state = new TailState();
        $state->activate($channel);
        $state->messageStart('evt-1', '10:00:00', 'UserRegistered', 'agg-001...', 'corr-123');

        $state->deactivate();
        $state->activate($channel);

        // Same eventId — but since we deactivated, lastEventId was cleared; should fire again
        $state->messageStart('evt-1', '10:00:00', 'UserRegistered', 'agg-001...', 'corr-123');
    }
}
