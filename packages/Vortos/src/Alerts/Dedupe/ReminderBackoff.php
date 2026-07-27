<?php

declare(strict_types=1);

namespace Vortos\Alerts\Dedupe;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * How long to stay quiet before reminding someone an alert is STILL firing.
 *
 * WHY THIS REPLACED A FIXED OCCURRENCE COUNT
 * ------------------------------------------
 * Reminders used to fire every Nth occurrence (default: every 10th). Alert sources are evaluated
 * on a fixed cadence — ours is every 60 seconds — so "every 10th occurrence" is really "every ten
 * minutes", forever, for as long as the condition lasts.
 *
 * For a condition that resolves itself that is fine. For one that does not, it is a pager that
 * never stops. Production had six such alerts open at once, including messages abandoned weeks
 * earlier that will never drain on their own: roughly thirty-six Slack messages an hour,
 * indefinitely. The predictable result is a muted channel, and a muted channel means the NEXT
 * alert — the real one — is not read either. An alerting system that trains people to ignore it is
 * worse than none, because it also buys false confidence.
 *
 * So reminders widen: soon at first, while someone might still be acting on it, then progressively
 * further apart once it is clear nobody is. The alert is never silently dropped — it is still
 * open, still counted, still visible — it simply stops shouting at a fixed interval.
 *
 * Doubling from ten minutes, capped at six hours: 10m → 20m → 40m → 1h20 → 2h40 → 6h → 6h → …
 * A day-long outage produces about nine reminders instead of a hundred and forty-four.
 */
final readonly class ReminderBackoff
{
    public function __construct(
        public int $initialSeconds = 600,
        public int $maxSeconds = 21_600,
        public int $multiplier = 2,
    ) {
        if ($initialSeconds < 1) {
            throw new InvalidArgumentException('ReminderBackoff initialSeconds must be >= 1.');
        }
        if ($maxSeconds < $initialSeconds) {
            throw new InvalidArgumentException('ReminderBackoff maxSeconds must be >= initialSeconds.');
        }
        if ($multiplier < 1) {
            throw new InvalidArgumentException('ReminderBackoff multiplier must be >= 1.');
        }
    }

    /**
     * Whether enough quiet time has passed to remind about this alert again.
     *
     * A state with no recorded notification has never been announced, so it is due immediately —
     * that is the "first sighting" case and it must never be delayed.
     */
    public function isDue(AlertState $state, DateTimeImmutable $now): bool
    {
        if ($state->lastNotifiedAt === null) {
            return true;
        }

        $elapsed = $now->getTimestamp() - $state->lastNotifiedAt->getTimestamp();

        return $elapsed >= $this->intervalFor($state);
    }

    /**
     * The current quiet period for this alert, widening with each reminder already sent.
     *
     * Derived from how many reminders have gone out rather than from the occurrence count, so the
     * spacing does not change if the evaluation cadence does. A source that ticks every 10 seconds
     * and one that ticks every 5 minutes produce the same reminder schedule.
     */
    public function intervalFor(AlertState $state): int
    {
        $interval = $this->initialSeconds;

        for ($i = 0; $i < $state->reminderCount; $i++) {
            $interval *= $this->multiplier;

            if ($interval >= $this->maxSeconds) {
                return $this->maxSeconds;
            }
        }

        return min($interval, $this->maxSeconds);
    }
}
