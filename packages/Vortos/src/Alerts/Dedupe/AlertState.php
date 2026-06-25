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
    ) {}

    public static function firstSeen(string $fingerprint, DateTimeImmutable $now): self
    {
        return new self($fingerprint, AlertStateStatus::Open, $now, $now, 1);
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
        );
    }
}
