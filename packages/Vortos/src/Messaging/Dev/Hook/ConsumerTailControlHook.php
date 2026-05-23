<?php

declare(strict_types=1);

namespace Vortos\Messaging\Dev\Hook;

use Vortos\Domain\Event\EventEnvelope;
use Vortos\Messaging\Dev\Channel\RedisTailChannel;
use Vortos\Messaging\Dev\TailState;
use Vortos\Messaging\Hook\Attribute\BeforeHandler;

#[BeforeHandler(priority: 100)]
final class ConsumerTailControlHook
{
    public function __construct(
        private readonly TailState $state,
        private readonly ?\Redis $redis,
    ) {}

    public function __invoke(EventEnvelope $envelope, string $consumerName, string $handlerId): void
    {
        if ($this->redis === null) {
            return;
        }

        $active = (bool) $this->redis->exists('vortos:tail-ctrl:' . $consumerName);

        if ($active && !$this->state->isActive()) {
            $this->state->activate(new RedisTailChannel($this->redis, $consumerName));
            return;
        }

        if (!$active && $this->state->isActive()) {
            $this->state->streamEnd();
            $this->state->deactivate();
        }
    }
}
