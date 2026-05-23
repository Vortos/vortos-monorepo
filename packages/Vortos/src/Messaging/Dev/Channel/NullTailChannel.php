<?php

declare(strict_types=1);

namespace Vortos\Messaging\Dev\Channel;

use Vortos\Messaging\Hook\HandlerOutcome;

final class NullTailChannel implements TailChannelInterface
{
    public function messageStart(string $eventId, string $time, string $eventShort, string $aggId, string $corr): void {}

    public function handlerRetry(string $eventId, string $handlerId, int $attempt, float $latencyMs, string $error): void {}

    public function handlerResult(string $eventId, string $handlerId, HandlerOutcome $outcome, int $attempts, float $latencyMs, ?\Throwable $throwable): void {}

    public function eventFlush(): void {}

    public function streamEnd(): void {}
}
