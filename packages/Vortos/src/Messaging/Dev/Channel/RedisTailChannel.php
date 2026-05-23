<?php

declare(strict_types=1);

namespace Vortos\Messaging\Dev\Channel;

use Vortos\Messaging\Hook\HandlerOutcome;

final class RedisTailChannel implements TailChannelInterface
{
    private string $redisChannel;

    public function __construct(private readonly \Redis $redis, string $consumerName)
    {
        $this->redisChannel = 'vortos:tail:' . $consumerName;
    }

    public function messageStart(string $eventId, string $time, string $eventShort, string $aggId, string $corr): void
    {
        $this->publish([
            'type'        => 'message_start',
            'event_id'    => $eventId,
            'time'        => $time,
            'event_short' => $eventShort,
            'agg_id'      => $aggId,
            'corr'        => $corr,
        ]);
    }

    public function handlerRetry(string $eventId, string $handlerId, int $attempt, float $latencyMs, string $error): void
    {
        $this->publish([
            'type'       => 'handler_retry',
            'event_id'   => $eventId,
            'handler_id' => $handlerId,
            'attempt'    => $attempt,
            'latency_ms' => $latencyMs,
            'error'      => $error,
        ]);
    }

    public function handlerResult(string $eventId, string $handlerId, HandlerOutcome $outcome, int $attempts, float $latencyMs, ?\Throwable $throwable): void
    {
        $this->publish([
            'type'       => 'handler_result',
            'event_id'   => $eventId,
            'handler_id' => $handlerId,
            'outcome'    => $outcome->value,
            'attempts'   => $attempts,
            'latency_ms' => $latencyMs,
            'error'      => $throwable?->getMessage(),
        ]);
    }

    public function eventFlush(): void
    {
        $this->publish(['type' => 'event_flush']);
    }

    public function streamEnd(): void
    {
        $this->publish(['type' => 'stream_end']);
    }

    /** @param array<string, mixed> $data */
    private function publish(array $data): void
    {
        $this->redis->publish($this->redisChannel, (string) json_encode($data));
    }
}
