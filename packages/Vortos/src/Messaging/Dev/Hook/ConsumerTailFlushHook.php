<?php

declare(strict_types=1);

namespace Vortos\Messaging\Dev\Hook;

use Vortos\Domain\Event\EventEnvelope;
use Vortos\Messaging\Dev\TailState;
use Vortos\Messaging\Hook\Attribute\AfterConsume;

#[AfterConsume]
final class ConsumerTailFlushHook
{
    public function __construct(private readonly TailState $state) {}

    public function __invoke(EventEnvelope $envelope, string $consumerName, ?\Throwable $throwable = null): void
    {
        $this->state->eventFlush();
    }
}
