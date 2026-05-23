<?php

declare(strict_types=1);

namespace Vortos\Tests\Messaging\Dev;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Vortos\Messaging\Dev\Channel\ConsoleTailChannel;
use Vortos\Messaging\Dev\TailRenderer;
use Vortos\Messaging\Hook\HandlerOutcome;

final class ConsoleTailChannelTest extends TestCase
{
    private function channel(): array
    {
        $output   = new BufferedOutput();
        $renderer = new TailRenderer($output);
        $channel  = new ConsoleTailChannel($renderer);
        return [$output, $renderer, $channel];
    }

    public function test_message_start_buffers_in_renderer(): void
    {
        [$output, , $channel] = $this->channel();

        $channel->messageStart('evt-1', '10:00:00', 'UserRegistered', 'agg-001...', 'corr-123');
        $channel->handlerResult('evt-1', 'MyHandler::handle', HandlerOutcome::Succeeded, 1, 14.5, null);
        $channel->streamEnd();

        $this->assertStringContainsString('UserRegistered', $output->fetch());
    }

    public function test_handler_retry_prints_immediately(): void
    {
        [$output, , $channel] = $this->channel();

        $channel->messageStart('evt-1', '10:00:00', 'UserRegistered', 'agg-001...', 'corr-123');
        $channel->handlerRetry('evt-1', 'MyHandler::handle', 1, 150.0, 'Connection refused');

        // No flush needed — should be in output already
        $result = $output->fetch();
        $this->assertStringContainsString('attempt', $result);
        $this->assertStringContainsString('Connection refused', $result);
    }

    public function test_handler_result_extracts_message_from_throwable(): void
    {
        [$output, , $channel] = $this->channel();

        $channel->messageStart('evt-1', '10:00:00', 'UserRegistered', 'agg-001...', 'corr-123');
        $channel->handlerResult('evt-1', 'MyHandler::handle', HandlerOutcome::DeadLettered, 3, 14.5, new \RuntimeException('Connection refused'));
        $channel->streamEnd();

        $result = $output->fetch();
        $this->assertStringContainsString('DLQ', $result);
        $this->assertStringContainsString('Connection refused', $result);
    }

    public function test_null_throwable_produces_ok_result(): void
    {
        [$output, , $channel] = $this->channel();

        $channel->messageStart('evt-1', '10:00:00', 'UserRegistered', 'agg-001...', 'corr-123');
        $channel->handlerResult('evt-1', 'MyHandler::handle', HandlerOutcome::Succeeded, 1, 14.5, null);
        $channel->streamEnd();

        $this->assertStringContainsString('OK', $output->fetch());
    }

    public function test_stream_end_flushes_buffered_output(): void
    {
        [$output, , $channel] = $this->channel();

        $channel->messageStart('evt-1', '10:00:00', 'OrderPlaced', 'agg-002...', 'corr-456');
        $channel->handlerResult('evt-1', 'OrderHandler::handle', HandlerOutcome::Succeeded, 1, 8.0, null);

        // Nothing flushed yet
        $this->assertSame('', $output->fetch());

        $channel->streamEnd();

        $this->assertStringContainsString('OrderPlaced', $output->fetch());
    }
}
