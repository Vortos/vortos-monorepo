<?php

declare(strict_types=1);

namespace Vortos\Alerts\Dedupe;

use DateTimeImmutable;
use Vortos\Alerts\Event\AlertEvent;

/**
 * Pure fingerprint + window collapse (§3.3 stage 1) — a storm of N identical alerts collapses to
 * one outbound notification, with a "still firing" reminder on a WIDENING schedule, never N pages.
 *
 * Reminders used to fire every Nth occurrence. Because sources are evaluated on a fixed cadence,
 * that is really a fixed time interval that never stops: production ran six permanently-open
 * alerts at roughly thirty-six Slack messages an hour, forever. The reliable outcome of that is a
 * muted channel — and a muted channel silences the next real alert too. {@see ReminderBackoff}
 * replaces it with intervals that widen the longer a condition stays unaddressed.
 */
final class Dedupe
{
    public function __construct(
        private readonly ReminderBackoff $backoff = new ReminderBackoff(),
    ) {}

    public function evaluate(AlertEvent $event, ?AlertState $previous, DedupeWindow $window, DateTimeImmutable $now): DedupeOutcome
    {
        $fingerprint = Fingerprint::of($event);

        // Never seen, or quiet for long enough that this is a genuinely new episode rather than a
        // continuation — announce it.
        if ($previous === null || $this->windowExpired($previous, $window, $now)) {
            return new DedupeOutcome(DedupeDecision::New, AlertState::firstSeen($fingerprint, $now, $event->ruleId));
        }

        $next = $previous->withOccurrence($now);

        // Still firing. Remind only when the widening quiet period has elapsed — measured from the
        // last time anyone was actually TOLD, not from how many times we happened to look.
        if ($this->backoff->isDue($next, $now)) {
            return new DedupeOutcome(DedupeDecision::Digest, $next->withReminderSent($now));
        }

        return new DedupeOutcome(DedupeDecision::Deduped, $next);
    }

    private function windowExpired(AlertState $previous, DedupeWindow $window, DateTimeImmutable $now): bool
    {
        return ($now->getTimestamp() - $previous->lastSeenAt->getTimestamp()) > $window->seconds;
    }
}
