<?php

declare(strict_types=1);

namespace Vortos\Alerts\Escalation;

use DateTimeImmutable;

/** Persisted escalation progress for one fingerprint — immutable; every transition produces a new instance. */
final readonly class EscalationState
{
    public function __construct(
        public string $fingerprint,
        public int $currentTier,
        public DateTimeImmutable $tierStartedAt,
        public bool $stopped,
    ) {}

    public static function start(string $fingerprint, DateTimeImmutable $now): self
    {
        return new self($fingerprint, 0, $now, false);
    }

    public function withTier(int $tier, DateTimeImmutable $now): self
    {
        return new self($this->fingerprint, $tier, $now, false);
    }

    public function withStopped(): self
    {
        return new self($this->fingerprint, $this->currentTier, $this->tierStartedAt, true);
    }
}
