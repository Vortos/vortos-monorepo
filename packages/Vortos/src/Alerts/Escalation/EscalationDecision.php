<?php

declare(strict_types=1);

namespace Vortos\Alerts\Escalation;

final readonly class EscalationDecision
{
    public function __construct(
        public EscalationOutcome $outcome,
        public ?int $tier,
        public string $reason,
    ) {}

    public static function notify(int $tier, string $reason): self
    {
        return new self(EscalationOutcome::Notify, $tier, $reason);
    }

    public static function wait(string $reason): self
    {
        return new self(EscalationOutcome::Wait, null, $reason);
    }

    public static function suppress(string $reason): self
    {
        return new self(EscalationOutcome::Suppress, null, $reason);
    }

    public static function stop(string $reason): self
    {
        return new self(EscalationOutcome::Stop, null, $reason);
    }
}
