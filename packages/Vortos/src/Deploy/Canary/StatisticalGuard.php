<?php

declare(strict_types=1);

namespace Vortos\Deploy\Canary;

/**
 * Pure deterministic function: given a set of metric evaluations and the accumulated
 * breach state, return a canary decision. No I/O, no clock (injected as params).
 *
 * Rules (all from the build plan §3):
 *  1. sampleCount < minSamples → Inconclusive (never act on < N data points).
 *  2. No breach AND sampleCount >= minSamples → Progress.
 *  3. Breach sustained for >= breachIntervals consecutive intervals → Rollback.
 *  4. Inconclusive/Hold past holdDeadline (from first hold start) → Rollback (fail-closed).
 *  5. Breach but not yet sustained → Hold.
 *
 * RelativeToBaseline correctness: a region-wide spike moves both colors equally,
 * so staged_value ≤ stable_value × (1 + tolerance) is not breached → Progress.
 * This logic lives in the Analyzer, not here; this guard only sees pre-evaluated
 * MetricEvaluation::$breached booleans.
 */
final class StatisticalGuard
{
    public function decide(
        /** @param list<MetricEvaluation> $evaluations */
        array $evaluations,
        int $totalSamples,
        int $consecutiveBreach,
        CanaryWindow $window,
        ?\DateTimeImmutable $holdStartedAt,
        \DateTimeImmutable $now,
    ): CanaryDecision {
        // Rule 1 — insufficient samples
        if ($totalSamples < $window->minSamples) {
            if ($this->isHoldDeadlineExceeded($holdStartedAt, $window, $now)) {
                return CanaryDecision::Rollback;
            }

            return CanaryDecision::Inconclusive;
        }

        $anyBreach = $this->hasAnyBreach($evaluations);

        // Rule 2 — all healthy
        if (!$anyBreach) {
            return CanaryDecision::Progress;
        }

        // Rule 4 — sustained Inconclusive/Hold past deadline
        if ($this->isHoldDeadlineExceeded($holdStartedAt, $window, $now)) {
            return CanaryDecision::Rollback;
        }

        // Rule 3 — sustained breach
        if ($consecutiveBreach >= $window->breachIntervals) {
            return CanaryDecision::Rollback;
        }

        // Rule 5 — breach but not yet sustained
        return CanaryDecision::Hold;
    }

    private function hasAnyBreach(array $evaluations): bool
    {
        foreach ($evaluations as $eval) {
            if ($eval->breached) {
                return true;
            }
        }

        return false;
    }

    private function isHoldDeadlineExceeded(
        ?\DateTimeImmutable $holdStartedAt,
        CanaryWindow $window,
        \DateTimeImmutable $now,
    ): bool {
        if ($holdStartedAt === null || $window->holdDeadlineSeconds === 0) {
            return false;
        }

        return ($now->getTimestamp() - $holdStartedAt->getTimestamp()) > $window->holdDeadlineSeconds;
    }
}
