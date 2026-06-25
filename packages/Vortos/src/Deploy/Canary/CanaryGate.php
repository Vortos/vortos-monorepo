<?php

declare(strict_types=1);

namespace Vortos\Deploy\Canary;

/**
 * Stateful canary gate for a single deploy run.
 *
 * Wraps CanaryAnalyzerInterface and StatisticalGuard, tracking consecutive-breach
 * count and hold-start timestamp across repeated gate() calls (one call per weight
 * step). The gate is fail-closed: persistent Inconclusive/Hold past holdDeadline
 * returns Rollback.
 *
 * The caller (StepExecutor) is responsible for:
 *   1. Calling gate() after each WeightedRoute step.
 *   2. On Rollback: calling CutoverCoordinator.cutover(prevRoute) and recording audit.
 *   3. On Progress: advancing to the next weight step.
 *   4. On Hold: re-calling gate() after a backoff (deadline is enforced here).
 */
final class CanaryGate
{
    private int $consecutiveBreach = 0;
    private ?\DateTimeImmutable $holdStartedAt = null;

    public function __construct(
        private readonly CanaryAnalyzerInterface $analyzer,
        private readonly StatisticalGuard $guard,
    ) {}

    public function gate(CanaryAnalysisRequest $request): CanaryVerdict
    {
        try {
            $verdict = $this->analyzer->analyze($request);
        } catch (\Throwable $e) {
            $verdict = new CanaryVerdict(
                decision: CanaryDecision::Inconclusive,
                evaluations: [],
                reason: sprintf('analyzer exception: %s', $e->getMessage()),
                totalSamples: 0,
                at: $request->at,
            );
        }

        $now = $request->at;

        // Count consecutive breaches for the StatisticalGuard
        $anyBreach = false;
        foreach ($verdict->evaluations as $eval) {
            if ($eval->breached) {
                $anyBreach = true;
                break;
            }
        }

        $isIndeterminate = in_array($verdict->decision, [CanaryDecision::Hold, CanaryDecision::Inconclusive], true);

        if ($anyBreach || $isIndeterminate) {
            $this->consecutiveBreach++;
            if ($this->holdStartedAt === null) {
                $this->holdStartedAt = $now;
            }
        } else {
            $this->consecutiveBreach = 0;
        }

        $decision = $this->guard->decide(
            evaluations: $verdict->evaluations,
            totalSamples: $verdict->totalSamples,
            consecutiveBreach: $this->consecutiveBreach,
            window: $request->window,
            holdStartedAt: $this->holdStartedAt,
            now: $now,
        );

        if ($decision === CanaryDecision::Progress) {
            $this->consecutiveBreach = 0;
            $this->holdStartedAt = null;
        }

        return new CanaryVerdict(
            decision: $decision,
            evaluations: $verdict->evaluations,
            reason: $verdict->reason,
            totalSamples: $verdict->totalSamples,
            at: $now,
        );
    }

    public function reset(): void
    {
        $this->consecutiveBreach = 0;
        $this->holdStartedAt = null;
    }
}
