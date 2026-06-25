<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Unit\Slo;

use PHPUnit\Framework\TestCase;
use Vortos\Observability\Slo\BurnRatePolicy;
use Vortos\Observability\Slo\Slo;
use Vortos\Observability\Slo\SloArtifactRenderer;
use Vortos\Observability\Slo\SloRegistry;
use Vortos\Observability\Slo\SloWindow;

final class SloTest extends TestCase
{
    public function test_objective_must_be_between_0_and_1_exclusive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Slo('availability', 1.0, SloWindow::days(30), 'metric:ref');
    }

    public function test_objective_zero_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Slo('availability', 0.0, SloWindow::days(30), 'metric:ref');
    }

    public function test_error_budget_is_one_minus_objective(): void
    {
        $slo = new Slo('availability', 0.999, SloWindow::days(30), 'metric:ref');

        self::assertEqualsWithDelta(0.001, $slo->errorBudget()->fraction, 1e-9);
    }

    public function test_burn_rate_scales_with_observed_failure_rate(): void
    {
        $slo = new Slo('availability', 0.99, SloWindow::days(30), 'metric:ref');
        $budget = $slo->errorBudget();

        // Observed failure rate equal to the budget fraction ⇒ burn rate 1.0 (on pace).
        self::assertEqualsWithDelta(1.0, $budget->burnRate(0.01), 1e-9);
        // Double the budget fraction ⇒ burning 2x too fast.
        self::assertEqualsWithDelta(2.0, $budget->burnRate(0.02), 1e-9);
    }

    public function test_window_seconds_must_be_at_least_one_minute(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SloWindow(59);
    }

    public function test_burn_rate_policy_rejects_fast_window_not_shorter_than_slow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BurnRatePolicy(SloWindow::hours(6), 14.4, SloWindow::hours(1), 6.0);
    }

    public function test_multi_burn_rate_requires_both_fast_and_slow_to_fire(): void
    {
        $policy = BurnRatePolicy::googleSreDefault();

        self::assertTrue($policy->isPageWorthy(20.0, 10.0));
        self::assertFalse($policy->isPageWorthy(20.0, 1.0)); // fast spike alone, slow window calm
        self::assertFalse($policy->isPageWorthy(1.0, 10.0)); // slow burn alone, no fast spike
    }

    public function test_registry_rejects_duplicate_names(): void
    {
        $registry = new SloRegistry([new Slo('a', 0.99, SloWindow::days(30), 'm1')]);

        $this->expectException(\InvalidArgumentException::class);
        $registry->add(new Slo('a', 0.95, SloWindow::days(7), 'm2'));
    }

    public function test_registry_unknown_slo_throws(): void
    {
        $registry = new SloRegistry();

        $this->expectException(\InvalidArgumentException::class);
        $registry->get('missing');
    }

    public function test_artifact_renderer_is_deterministic_and_pure(): void
    {
        $slo = new Slo('availability', 0.99, SloWindow::days(30), 'metric:ref');
        $policy = BurnRatePolicy::googleSreDefault();
        $renderer = new SloArtifactRenderer();

        $rendered = $renderer->render($slo, $policy);

        self::assertSame($rendered, $renderer->render($slo, $policy));
        self::assertSame('availability', $rendered['name']);
        self::assertSame(0.99, $rendered['objective']);
    }
}
