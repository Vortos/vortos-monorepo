<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Canary;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Canary\CanaryComparator;
use Vortos\Deploy\Canary\CanaryDecision;
use Vortos\Deploy\Canary\CanaryWindow;
use Vortos\Deploy\Canary\MetricEvaluation;
use Vortos\Deploy\Canary\StatisticalGuard;
use Vortos\Observability\Slo\Slo;
use Vortos\Observability\Slo\SloWindow;

final class StatisticalGuardTest extends TestCase
{
    private StatisticalGuard $guard;
    private CanaryWindow $window;
    private Slo $slo;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->guard = new StatisticalGuard();
        $this->window = new CanaryWindow(
            windowSeconds: 300,
            stepSeconds: 15,
            minSamples: 5,
            breachIntervals: 3,
            holdDeadlineSeconds: 600,
        );
        $this->slo = new Slo('error-rate', 0.99, new SloWindow(86400), 'http_errors_total');
        $this->now = new \DateTimeImmutable('2026-01-01T10:00:00Z');
    }

    private function eval(bool $breached): MetricEvaluation
    {
        return new MetricEvaluation(
            sloName: 'error-rate',
            comparator: CanaryComparator::RelativeToBaseline,
            stagedValue: 0.05,
            stableValue: 0.01,
            breached: $breached,
            reason: $breached ? 'breach' : 'ok',
        );
    }

    public function test_single_breach_sample_below_min_samples_is_inconclusive(): void
    {
        $decision = $this->guard->decide(
            evaluations: [$this->eval(true)],
            totalSamples: 1,  // below minSamples=5
            consecutiveBreach: 1,
            window: $this->window,
            holdStartedAt: null,
            now: $this->now,
        );

        self::assertSame(CanaryDecision::Inconclusive, $decision);
    }

    public function test_sustained_breach_above_threshold_is_rollback(): void
    {
        $decision = $this->guard->decide(
            evaluations: [$this->eval(true)],
            totalSamples: 10,
            consecutiveBreach: 3,  // >= breachIntervals=3
            window: $this->window,
            holdStartedAt: null,
            now: $this->now,
        );

        self::assertSame(CanaryDecision::Rollback, $decision);
    }

    public function test_intermittent_breach_not_consecutive_is_hold(): void
    {
        // consecutiveBreach=1 — not yet sustained
        $decision = $this->guard->decide(
            evaluations: [$this->eval(true)],
            totalSamples: 10,
            consecutiveBreach: 1,
            window: $this->window,
            holdStartedAt: null,
            now: $this->now,
        );

        self::assertSame(CanaryDecision::Hold, $decision);
    }

    public function test_empty_evaluations_with_sufficient_samples_is_progress(): void
    {
        $decision = $this->guard->decide(
            evaluations: [],
            totalSamples: 10,
            consecutiveBreach: 0,
            window: $this->window,
            holdStartedAt: null,
            now: $this->now,
        );

        self::assertSame(CanaryDecision::Progress, $decision);
    }

    public function test_all_healthy_with_sufficient_samples_is_progress(): void
    {
        $decision = $this->guard->decide(
            evaluations: [$this->eval(false), $this->eval(false)],
            totalSamples: 10,
            consecutiveBreach: 0,
            window: $this->window,
            holdStartedAt: null,
            now: $this->now,
        );

        self::assertSame(CanaryDecision::Progress, $decision);
    }

    public function test_inconclusive_past_hold_deadline_is_rollback(): void
    {
        // Hold started 700s ago, deadline is 600s
        $holdStart = $this->now->modify('-700 seconds');

        $decision = $this->guard->decide(
            evaluations: [$this->eval(false)],
            totalSamples: 2,  // below minSamples
            consecutiveBreach: 0,
            window: $this->window,
            holdStartedAt: $holdStart,
            now: $this->now,
        );

        self::assertSame(CanaryDecision::Rollback, $decision);
    }

    public function test_hold_within_deadline_is_inconclusive_not_rollback(): void
    {
        $holdStart = $this->now->modify('-100 seconds');

        $decision = $this->guard->decide(
            evaluations: [$this->eval(false)],
            totalSamples: 2,  // below minSamples
            consecutiveBreach: 0,
            window: $this->window,
            holdStartedAt: $holdStart,
            now: $this->now,
        );

        self::assertSame(CanaryDecision::Inconclusive, $decision);
    }

    public function test_relative_equal_spikes_both_colors_is_progress(): void
    {
        // Both colors spike equally — relative comparator should not breach
        // This is evaluated by the Analyzer, not the Guard, but we test the guard
        // sees no breach when evaluations say no breach (Guard doesn't recompute ratio)
        $eval = new MetricEvaluation(
            sloName: 'error-rate',
            comparator: CanaryComparator::RelativeToBaseline,
            stagedValue: 0.10,
            stableValue: 0.10,  // equal — within tolerance
            breached: false,
            reason: 'staged within tolerance of baseline',
        );

        $decision = $this->guard->decide(
            evaluations: [$eval],
            totalSamples: 10,
            consecutiveBreach: 0,
            window: $this->window,
            holdStartedAt: null,
            now: $this->now,
        );

        self::assertSame(CanaryDecision::Progress, $decision);
    }

    public function test_staged_spike_stable_flat_is_rollback(): void
    {
        $eval = new MetricEvaluation(
            sloName: 'error-rate',
            comparator: CanaryComparator::RelativeToBaseline,
            stagedValue: 0.50,
            stableValue: 0.01,
            breached: true,
            reason: 'staged >> baseline',
        );

        $decision = $this->guard->decide(
            evaluations: [$eval],
            totalSamples: 10,
            consecutiveBreach: 3,
            window: $this->window,
            holdStartedAt: null,
            now: $this->now,
        );

        self::assertSame(CanaryDecision::Rollback, $decision);
    }

    public function test_exactly_at_breach_intervals_is_rollback(): void
    {
        // consecutiveBreach exactly equals breachIntervals (not greater-than)
        $decision = $this->guard->decide(
            evaluations: [$this->eval(true)],
            totalSamples: 10,
            consecutiveBreach: 3,  // = breachIntervals
            window: $this->window,
            holdStartedAt: null,
            now: $this->now,
        );

        self::assertSame(CanaryDecision::Rollback, $decision);
    }

    public function test_exactly_at_min_samples_is_not_inconclusive(): void
    {
        // totalSamples exactly equals minSamples — should be evaluated, not Inconclusive
        $decision = $this->guard->decide(
            evaluations: [$this->eval(false)],
            totalSamples: 5,  // = minSamples
            consecutiveBreach: 0,
            window: $this->window,
            holdStartedAt: null,
            now: $this->now,
        );

        self::assertSame(CanaryDecision::Progress, $decision);
    }

    public function test_breach_at_exactly_deadline_plus_one_second_is_rollback(): void
    {
        $holdStart = $this->now->modify(sprintf('-%d seconds', $this->window->holdDeadlineSeconds + 1));

        $decision = $this->guard->decide(
            evaluations: [$this->eval(true)],
            totalSamples: 10,
            consecutiveBreach: 1,
            window: $this->window,
            holdStartedAt: $holdStart,
            now: $this->now,
        );

        self::assertSame(CanaryDecision::Rollback, $decision);
    }

    public function test_zero_hold_deadline_never_deadline_triggers(): void
    {
        $windowNoDeadline = new CanaryWindow(300, 15, 5, 3, holdDeadlineSeconds: 0);

        // Even with old holdStart, 0-deadline means never auto-rollback on deadline
        $holdStart = $this->now->modify('-99999 seconds');

        $decision = $this->guard->decide(
            evaluations: [$this->eval(false)],
            totalSamples: 2,  // below minSamples
            consecutiveBreach: 0,
            window: $windowNoDeadline,
            holdStartedAt: $holdStart,
            now: $this->now,
        );

        self::assertSame(CanaryDecision::Inconclusive, $decision);
    }
}
