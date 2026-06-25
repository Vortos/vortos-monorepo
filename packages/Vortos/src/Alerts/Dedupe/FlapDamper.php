<?php

declare(strict_types=1);

namespace Vortos\Alerts\Dedupe;

use DateTimeImmutable;

/**
 * Detects rapid open→resolve→open cycles within a window (§3.3): a flapping signal
 * escalates **once** and is then damped — a single "flapping" notice — rather than
 * paging on every toggle.
 *
 * Call {@see recordTransition()} every time a fingerprint re-opens after having been
 * resolved (i.e. the caller observed `Resolved → Open`). A re-open while still `Open`
 * is ordinary dedupe (handled by {@see Dedupe}), not a flap transition.
 */
final class FlapDamper
{
    public function __construct(
        private readonly int $maxTransitions = 3,
    ) {}

    public function recordTransition(AlertState $state, DateTimeImmutable $now, DedupeWindow $window): FlapOutcome
    {
        $windowStart = $state->flapWindowStartAt;
        $transitions = $state->flapTransitions;
        $escalatedAt = $state->flapEscalatedAt;

        if ($windowStart === null || ($now->getTimestamp() - $windowStart->getTimestamp()) > $window->seconds) {
            $windowStart = $now;
            $transitions = 1;
            $escalatedAt = null;
        } else {
            $transitions++;
        }

        $shouldEscalate = false;
        $isDamped = false;

        if ($transitions > $this->maxTransitions) {
            if ($escalatedAt === null) {
                $shouldEscalate = true;
                $escalatedAt = $now;
            } else {
                $isDamped = true;
            }
        }

        $next = $state->withFlap([
            'flapTransitions' => $transitions,
            'flapWindowStartAt' => $windowStart,
            'flapEscalatedAt' => $escalatedAt,
        ])->withStatus(AlertStateStatus::Open, $now);

        return new FlapOutcome($next, $shouldEscalate, $isDamped);
    }
}
