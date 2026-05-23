<?php

declare(strict_types=1);

namespace Vortos\Messaging\Hook\Attribute;

use Attribute;
use Vortos\Messaging\Hook\HandlerOutcome;

/**
 * Fires in ConsumerRunner after each handler invocation resolves.
 * Outside the middleware stack, once per handler per message.
 *
 * Default behaviour (on: []): fires for every terminal outcome — Succeeded,
 * SucceededAfterRetries, SkippedIdempotent, DiscardedReplayLimit, DeadLettered.
 * AttemptFailed (intermediate retry) is excluded unless explicitly listed.
 *
 * Use HandlerOutcome constants for common combinations:
 *   on: HandlerOutcome::TERMINAL_SUCCESS  — any success (first try or after retries)
 *   on: HandlerOutcome::TERMINAL_FAILURE  — DLQ or replay discard
 *   on: HandlerOutcome::TERMINAL          — all terminal outcomes (explicit default)
 *   on: [HandlerOutcome::AttemptFailed]   — opt in to intermediate retry failures
 *
 * Receives: EventEnvelope, consumerName, handlerId, HandlerOutcome, attempts, latencyMs, ?Throwable.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class AfterHandler
{
    /** @param list<HandlerOutcome> $on */
    public function __construct(
        public readonly ?string $event    = null,
        public readonly ?string $consumer = null,
        public readonly int     $priority = 0,
        public readonly array   $on       = [],
    ) {}
}
