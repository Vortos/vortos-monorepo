<?php

declare(strict_types=1);

namespace Vortos\Observability\Slo;

/**
 * The error budget derived from an {@see Slo}: `1 - objective`. The fraction of the
 * window's events that may fail before the SLO breaches — the budget Block 22
 * (canary) reads burn rate against.
 */
final readonly class ErrorBudget
{
    public float $fraction;

    public function __construct(
        public Slo $slo,
    ) {
        $this->fraction = 1.0 - $slo->objective;
    }

    /** Allowed failing events over the SLO's window, given a total event count. */
    public function allowedFailures(int $totalEvents): float
    {
        return $this->fraction * $totalEvents;
    }

    /** Burn rate: observed failure rate divided by the allowed budget fraction. */
    public function burnRate(float $observedFailureRate): float
    {
        if ($this->fraction <= 0.0) {
            return $observedFailureRate > 0.0 ? INF : 0.0;
        }

        return $observedFailureRate / $this->fraction;
    }
}
