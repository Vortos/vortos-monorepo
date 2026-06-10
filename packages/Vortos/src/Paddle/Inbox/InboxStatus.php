<?php

declare(strict_types=1);

namespace Vortos\Paddle\Inbox;

/**
 * Lifecycle of a webhook inbox row.
 *
 *   pending   — accepted from Paddle, awaiting (re)processing
 *   processed — every matching handler completed successfully
 *   dead      — retry attempts exhausted; requires paddle:inbox:replay
 *
 * There is no transient "failed" state: a failed attempt stays `pending`
 * with attempts incremented and next_attempt_at pushed back (same model as
 * the Paddle outbox relay), so the worker query stays a single status scan.
 */
enum InboxStatus: string
{
    case Pending   = 'pending';
    case Processed = 'processed';
    case Dead      = 'dead';
}
