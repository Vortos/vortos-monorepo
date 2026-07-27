<?php

declare(strict_types=1);

namespace Vortos\Alerts\Dedupe;

use DateTimeImmutable;

/**
 * Persisted dedupe/flap-damper state for one fingerprint (§3.3). Immutable VO — every
 * transition produces a new instance; stores persist whichever instance was returned.
 */
final readonly class AlertState
{
    public function __construct(
        public string $fingerprint,
        public AlertStateStatus $status,
        public DateTimeImmutable $firstSeenAt,
        public DateTimeImmutable $lastSeenAt,
        public int $occurrenceCount,
        public int $flapTransitions = 0,
        public ?DateTimeImmutable $flapWindowStartAt = null,
        public ?DateTimeImmutable $flapEscalatedAt = null,
        /**
         * When a notification was last actually SENT for this fingerprint — distinct from
         * lastSeenAt, which moves on every evaluation whether or not anyone was told.
         *
         * Repeat "still firing" reminders back off from this instant. Without it the only signal
         * available was the occurrence count, which for a condition evaluated on a fixed cadence
         * turns into a fixed-interval reminder that never stops.
         */
        public ?DateTimeImmutable $lastNotifiedAt = null,
        /**
         * How many reminders have gone out for this alert (the first announcement is not one).
         *
         * Drives the widening quiet period in {@see ReminderBackoff}. Counted separately from
         * occurrenceCount on purpose: occurrences track how often the condition was OBSERVED, which
         * depends entirely on the evaluation cadence, while this tracks how often somebody was
         * actually told — the only thing that should govern how loud we keep being.
         */
        public int $reminderCount = 0,
    ) {}

    public static function firstSeen(string $fingerprint, DateTimeImmutable $now): self
    {
        // A first sighting is always notified, so the backoff clock starts here.
        return new self($fingerprint, AlertStateStatus::Open, $now, $now, 1, lastNotifiedAt: $now);
    }

    public function withOccurrence(DateTimeImmutable $now): self
    {
        return new self(
            $this->fingerprint,
            AlertStateStatus::Open,
            $this->firstSeenAt,
            $now,
            $this->occurrenceCount + 1,
            $this->flapTransitions,
            $this->flapWindowStartAt,
            $this->flapEscalatedAt,
            $this->lastNotifiedAt,
            $this->reminderCount,
        );
    }

    /** Marks that a reminder was actually sent, restarting and widening the backoff clock. */
    public function withReminderSent(DateTimeImmutable $now): self
    {
        return new self(
            $this->fingerprint,
            $this->status,
            $this->firstSeenAt,
            $this->lastSeenAt,
            $this->occurrenceCount,
            $this->flapTransitions,
            $this->flapWindowStartAt,
            $this->flapEscalatedAt,
            $now,
            $this->reminderCount + 1,
        );
    }

    public function withStatus(AlertStateStatus $status, DateTimeImmutable $now): self
    {
        return new self(
            $this->fingerprint,
            $status,
            $this->firstSeenAt,
            $now,
            $this->occurrenceCount,
            $this->flapTransitions,
            $this->flapWindowStartAt,
            $this->flapEscalatedAt,
            $this->lastNotifiedAt,
            $this->reminderCount,
        );
    }

    /** @param array{flapTransitions:int, flapWindowStartAt:?DateTimeImmutable, flapEscalatedAt:?DateTimeImmutable} $flap */
    public function withFlap(array $flap): self
    {
        return new self(
            $this->fingerprint,
            $this->status,
            $this->firstSeenAt,
            $this->lastSeenAt,
            $this->occurrenceCount,
            $flap['flapTransitions'],
            $flap['flapWindowStartAt'],
            $flap['flapEscalatedAt'],
            $this->lastNotifiedAt,
            $this->reminderCount,
        );
    }
}
