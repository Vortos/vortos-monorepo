<?php

declare(strict_types=1);

namespace Vortos\Messaging\Hook;

enum HandlerOutcome: string
{
    case Succeeded             = 'succeeded';
    case SucceededAfterRetries = 'succeeded_retried';
    case SkippedIdempotent     = 'skipped_idempotent';
    case DiscardedReplayLimit  = 'discarded_replay';
    case DeadLettered          = 'dead_lettered';
    /** Intermediate retry attempt failed — opt-in only, not in default filter. */
    case AttemptFailed         = 'attempt_failed';

    /** Any successful terminal outcome. */
    const TERMINAL_SUCCESS = [self::Succeeded, self::SucceededAfterRetries];

    /** Any permanent non-recoverable failure outcome. */
    const TERMINAL_FAILURE = [self::DeadLettered, self::DiscardedReplayLimit];

    /**
     * All terminal outcomes (every case except AttemptFailed).
     * This is the default filter when no `on` is specified on #[AfterHandler].
     */
    const TERMINAL = [
        self::Succeeded,
        self::SucceededAfterRetries,
        self::SkippedIdempotent,
        self::DiscardedReplayLimit,
        self::DeadLettered,
    ];
}
