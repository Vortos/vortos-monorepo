<?php

declare(strict_types=1);

namespace Vortos\Messaging\Tests\Dev;

use PHPUnit\Framework\TestCase;
use Vortos\Messaging\Dev\Channel\RedisTailChannel;
use Vortos\Messaging\Hook\HandlerOutcome;

final class RedisTailChannelTest extends TestCase
{
    private function makeRedis(): \Redis&\PHPUnit\Framework\MockObject\MockObject
    {
        return $this->createMock(\Redis::class);
    }

    public function test_message_start_publishes_to_correct_channel(): void
    {
        $redis = $this->makeRedis();
        $redis->expects($this->once())
            ->method('publish')
            ->with('vortos:tail:order-consumer', $this->callback(function (string $json): bool {
                $data = json_decode($json, true);
                return $data['type'] === 'message_start'
                    && $data['event_id'] === 'evt-001'
                    && $data['event_short'] === 'OrderPlaced';
            }));

        $channel = new RedisTailChannel($redis, 'order-consumer');
        $channel->messageStart('evt-001', '10:00:00', 'OrderPlaced', 'agg-001...', 'corr-123');
    }

    public function test_handler_retry_publishes_correct_fields(): void
    {
        $redis = $this->makeRedis();
        $redis->expects($this->once())
            ->method('publish')
            ->with('vortos:tail:user-consumer', $this->callback(function (string $json): bool {
                $data = json_decode($json, true);
                return $data['type'] === 'handler_retry'
                    && $data['handler_id'] === 'MyHandler::handle'
                    && $data['attempt'] === 2
                    && abs($data['latency_ms'] - 300.0) < 0.01
                    && $data['error'] === 'SMTP timeout';
            }));

        $channel = new RedisTailChannel($redis, 'user-consumer');
        $channel->handlerRetry('evt-001', 'MyHandler::handle', 2, 300.0, 'SMTP timeout');
    }

    public function test_handler_result_publishes_outcome_and_attempts(): void
    {
        $redis = $this->makeRedis();
        $redis->expects($this->once())
            ->method('publish')
            ->with('vortos:tail:user-consumer', $this->callback(function (string $json): bool {
                $data = json_decode($json, true);
                return $data['type'] === 'handler_result'
                    && $data['handler_id'] === 'MyHandler::handle'
                    && $data['outcome'] === 'dead_lettered'
                    && $data['attempts'] === 3
                    && abs($data['latency_ms'] - 14.5) < 0.01
                    && $data['error'] === 'Connection refused';
            }));

        $channel = new RedisTailChannel($redis, 'user-consumer');
        $channel->handlerResult('evt-001', 'MyHandler::handle', HandlerOutcome::DeadLettered, 3, 14.5, new \RuntimeException('Connection refused'));
    }

    public function test_handler_result_succeeded_publishes_null_error(): void
    {
        $redis = $this->makeRedis();
        $redis->expects($this->once())
            ->method('publish')
            ->with($this->anything(), $this->callback(function (string $json): bool {
                $data = json_decode($json, true);
                return $data['outcome'] === 'succeeded'
                    && $data['attempts'] === 1
                    && array_key_exists('error', $data)
                    && $data['error'] === null;
            }));

        $channel = new RedisTailChannel($redis, 'user-consumer');
        $channel->handlerResult('evt-001', 'MyHandler::handle', HandlerOutcome::Succeeded, 1, 14.5, null);
    }

    public function test_stream_end_publishes_stream_end_type(): void
    {
        $redis = $this->makeRedis();
        $redis->expects($this->once())
            ->method('publish')
            ->with('vortos:tail:user-consumer', $this->callback(function (string $json): bool {
                return json_decode($json, true)['type'] === 'stream_end';
            }));

        $channel = new RedisTailChannel($redis, 'user-consumer');
        $channel->streamEnd();
    }

    public function test_channel_name_uses_consumer_name(): void
    {
        $redis = $this->makeRedis();
        $redis->expects($this->once())
            ->method('publish')
            ->with('vortos:tail:payments-consumer', $this->anything());

        $channel = new RedisTailChannel($redis, 'payments-consumer');
        $channel->streamEnd();
    }
}
