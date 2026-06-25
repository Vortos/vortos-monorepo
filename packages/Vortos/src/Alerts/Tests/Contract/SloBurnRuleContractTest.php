<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Contract;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Alerts\Rule\AlertRule;
use Vortos\Alerts\Rule\AlertRuleEvaluator;
use Vortos\Alerts\Rule\AlertRuleKind;
use Vortos\Alerts\Rule\Condition\SloBurnCondition;
use Vortos\Alerts\Rule\Sample\BurnRateSample;
use Vortos\Alerts\Severity;
use Vortos\Observability\Slo\BurnRatePolicy;
use Vortos\Observability\Slo\Slo;
use Vortos\Observability\Slo\SloArtifactRenderer;
use Vortos\Observability\Slo\SloWindow;

/**
 * Proves the Block 16 → 17 hand-off shape is stable: a `SloArtifactRenderer` vector's
 * burn-rate thresholds are exactly what `slo_burn` rule evaluation pages against —
 * Block 17 never re-derives the fast/slow logic.
 */
final class SloBurnRuleContractTest extends TestCase
{
    public function test_rendered_thresholds_match_what_the_evaluator_pages_on(): void
    {
        $slo = new Slo('api-availability', 0.999, SloWindow::days(30), 'metric:http_success_ratio');
        $policy = BurnRatePolicy::googleSreDefault();

        $artifact = (new SloArtifactRenderer())->render($slo, $policy);

        $expectedFraction = $artifact['error_budget_fraction'];
        unset($artifact['error_budget_fraction']);

        self::assertSame([
            'name' => 'api-availability',
            'objective' => 0.999,
            'window_seconds' => 30 * 86400,
            'indicator_ref' => 'metric:http_success_ratio',
            'burn_rate' => [
                'fast' => ['window_seconds' => 3600, 'threshold' => 14.4],
                'slow' => ['window_seconds' => 21600, 'threshold' => 6.0],
            ],
        ], $artifact);
        self::assertEqualsWithDelta(0.001, $expectedFraction, 1e-12);

        $rule = new AlertRule('api-burn', Severity::Critical, AlertRuleKind::SloBurn, new SloBurnCondition($policy), sloRef: $slo->name);
        $evaluator = new AlertRuleEvaluator();
        $now = new DateTimeImmutable();

        $belowFastThreshold = $evaluator->evaluate($rule, new BurnRateSample(14.3, 6.0), 'prod', null, $now);
        $atBothThresholds = $evaluator->evaluate($rule, new BurnRateSample(14.4, 6.0), 'prod', null, $now);

        self::assertNull($belowFastThreshold, 'evaluator must use the exact rendered fast threshold, not an approximation');
        self::assertNotNull($atBothThresholds);
    }
}
