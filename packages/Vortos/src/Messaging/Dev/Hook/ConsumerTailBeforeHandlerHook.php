<?php

declare(strict_types=1);

namespace Vortos\Messaging\Dev\Hook;

use Vortos\Domain\Event\EventEnvelope;
use Vortos\Messaging\Dev\TailState;
use Vortos\Messaging\Hook\Attribute\BeforeHandler;

#[BeforeHandler]
final class ConsumerTailBeforeHandlerHook
{
    public function __construct(private readonly TailState $state) {}

    public function __invoke(EventEnvelope $envelope, string $consumerName, string $handlerId): void
    {
        if (!$this->state->isActive()) {
            return;
        }

        $aggId = $envelope->aggregateId !== ''
            ? mb_substr($envelope->aggregateId, 0, 22) . '...'
            : '—';

        $this->state->messageStart(
            eventId:    $envelope->eventId,
            time:       date('H:i:s'),
            eventShort: $this->shortName($envelope->payloadType),
            aggId:      $aggId,
            corr:       $envelope->metadata->correlationId ?? '—',
        );
    }

    private function shortName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        return end($parts);
    }
}
