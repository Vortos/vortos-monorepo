<?php

declare(strict_types=1);

namespace Vortos\Messaging\Dev;

use Vortos\Messaging\Dev\Channel\NullTailChannel;
use Vortos\Messaging\Dev\Channel\TailChannelInterface;
use Vortos\Messaging\Hook\HandlerOutcome;

/**
 * Shared singleton that routes per-handler events to the active TailChannel.
 *
 * Inactive by default — isActive() is a single property read, making the
 * production overhead of having the hooks registered negligible.
 *
 * Activation modes:
 *   - ConsoleTailChannel: vortos:consume --tail (direct output, same process)
 *   - RedisTailChannel:   Redis control key set by vortos:consumer:tail (pub/sub)
 */
final class TailState
{
    private bool $active           = false;
    private TailChannelInterface $channel;
    private ?string $lastEventId   = null;

    public function __construct()
    {
        $this->channel = new NullTailChannel();
    }

    public function activate(TailChannelInterface $channel): void
    {
        $this->active      = true;
        $this->channel     = $channel;
        $this->lastEventId = null;
    }

    public function deactivate(): void
    {
        $this->active      = false;
        $this->channel     = new NullTailChannel();
        $this->lastEventId = null;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function messageStart(string $eventId, string $time, string $eventShort, string $aggId, string $corr): void
    {
        if (!$this->active || $eventId === $this->lastEventId) {
            return;
        }

        $this->lastEventId = $eventId;
        $this->channel->messageStart($eventId, $time, $eventShort, $aggId, $corr);
    }

    public function handlerRetry(string $eventId, string $handlerId, int $attempt, float $latencyMs, string $error): void
    {
        if (!$this->active) {
            return;
        }

        $this->channel->handlerRetry($eventId, $handlerId, $attempt, $latencyMs, $error);
    }

    public function handlerResult(string $eventId, string $handlerId, HandlerOutcome $outcome, int $attempts, float $latencyMs, ?\Throwable $throwable): void
    {
        if (!$this->active) {
            return;
        }

        $this->channel->handlerResult($eventId, $handlerId, $outcome, $attempts, $latencyMs, $throwable);
    }

    public function eventFlush(): void
    {
        if ($this->active) {
            $this->channel->eventFlush();
        }
    }

    public function streamEnd(): void
    {
        $this->channel->streamEnd();
    }
}
