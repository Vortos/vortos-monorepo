<?php

declare(strict_types=1);

namespace Vortos\Messaging\Dev\Hook;

use Vortos\Domain\Event\EventEnvelope;
use Vortos\Messaging\Dev\TailState;
use Vortos\Messaging\Hook\Attribute\AfterHandler;
use Vortos\Messaging\Hook\HandlerOutcome;

#[AfterHandler(on: [
    HandlerOutcome::Succeeded,
    HandlerOutcome::SucceededAfterRetries,
    HandlerOutcome::SkippedIdempotent,
    HandlerOutcome::DiscardedReplayLimit,
    HandlerOutcome::DeadLettered,
    HandlerOutcome::AttemptFailed,
])]
final class ConsumerTailAfterHandlerHook
{
    public function __construct(private readonly TailState $state) {}

    public function __invoke(
        EventEnvelope  $envelope,
        string         $consumerName,
        string         $handlerId,
        HandlerOutcome $outcome,
        int            $attempts,
        float          $latencyMs,
        ?\Throwable    $throwable = null,
    ): void {
        if (!$this->state->isActive()) {
            return;
        }

        if ($outcome === HandlerOutcome::AttemptFailed) {
            $this->state->handlerRetry(
                eventId:   $envelope->eventId,
                handlerId: $handlerId,
                attempt:   $attempts,
                latencyMs: $latencyMs,
                error:     $throwable?->getMessage() ?? '',
            );
            return;
        }

        $this->state->handlerResult(
            eventId:   $envelope->eventId,
            handlerId: $handlerId,
            outcome:   $outcome,
            attempts:  $attempts,
            latencyMs: $latencyMs,
            throwable: $throwable,
        );
    }
}
