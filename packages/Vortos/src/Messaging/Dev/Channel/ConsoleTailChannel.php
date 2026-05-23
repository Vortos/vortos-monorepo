<?php

declare(strict_types=1);

namespace Vortos\Messaging\Dev\Channel;

use Vortos\Messaging\Dev\TailRenderer;
use Vortos\Messaging\Hook\HandlerOutcome;

final class ConsoleTailChannel implements TailChannelInterface
{
    public function __construct(private readonly TailRenderer $renderer) {}

    public function messageStart(string $eventId, string $time, string $eventShort, string $aggId, string $corr): void
    {
        $this->renderer->messageStart($eventId, $time, $eventShort, $aggId, $corr);
    }

    public function handlerRetry(string $eventId, string $handlerId, int $attempt, float $latencyMs, string $error): void
    {
        $this->renderer->handlerRetry($eventId, $handlerId, $attempt, $latencyMs, $error);
    }

    public function handlerResult(string $eventId, string $handlerId, HandlerOutcome $outcome, int $attempts, float $latencyMs, ?\Throwable $throwable): void
    {
        $this->renderer->handlerResult($eventId, $handlerId, $outcome, $attempts, $latencyMs, $throwable?->getMessage());
    }

    public function eventFlush(): void
    {
        $this->renderer->flush();
    }

    public function streamEnd(): void
    {
        $this->renderer->flush();
    }
}
