<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Unit\Rule;

use PHPUnit\Framework\TestCase;
use Vortos\Alerts\Rule\AlertRule;
use Vortos\Alerts\Rule\AlertRuleKind;
use Vortos\Alerts\Rule\AlertRuleSet;
use Vortos\Alerts\Rule\AlertRuleValidationException;
use Vortos\Alerts\Rule\AlertRuleValidator;
use Vortos\Alerts\Rule\Condition\NoCondition;
use Vortos\Alerts\Rule\Condition\SloBurnCondition;
use Vortos\Alerts\Rule\Condition\ThresholdCondition;
use Vortos\Alerts\Rule\Condition\ThresholdOperator;
use Vortos\Alerts\Severity;
use Vortos\Observability\Slo\BurnRatePolicy;
use Vortos\Observability\Slo\Slo;
use Vortos\Observability\Slo\SloRegistry;
use Vortos\Observability\Slo\SloWindow;

final class AlertRuleValidatorTest extends TestCase
{
    private function validator(): AlertRuleValidator
    {
        return new AlertRuleValidator();
    }

    public function test_in_range_threshold_rule_passes(): void
    {
        $rules = new AlertRuleSet([
            new AlertRule('errs', Severity::Critical, AlertRuleKind::ErrorRate, new ThresholdCondition(ThresholdOperator::GreaterThan, 0.05)),
        ]);

        $this->validator()->validate($rules);
        $this->addToAssertionCount(1);
    }

    public function test_wrong_condition_type_for_kind_rejected(): void
    {
        $rules = new AlertRuleSet([
            new AlertRule('bad', Severity::Critical, AlertRuleKind::ErrorRate, new NoCondition()),
        ]);

        $this->expectException(AlertRuleValidationException::class);
        $this->validator()->validate($rules);
    }

    public function test_duplicate_rule_id_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AlertRuleSet([
            new AlertRule('dup', Severity::Warning, AlertRuleKind::ErrorRate, new ThresholdCondition(ThresholdOperator::GreaterThan, 0.1)),
            new AlertRule('dup', Severity::Warning, AlertRuleKind::ErrorRate, new ThresholdCondition(ThresholdOperator::GreaterThan, 0.2)),
        ]);
    }

    public function test_dangling_slo_ref_rejected(): void
    {
        $policy = BurnRatePolicy::googleSreDefault();
        $rules = new AlertRuleSet([
            new AlertRule('burn', Severity::Critical, AlertRuleKind::SloBurn, new SloBurnCondition($policy), sloRef: 'nonexistent-slo'),
        ]);

        $sloRegistry = new SloRegistry([new Slo('real-slo', 0.999, SloWindow::days(30), 'metric:x')]);

        $this->expectException(AlertRuleValidationException::class);
        $this->validator()->validate($rules, $sloRegistry);
    }

    public function test_existing_slo_ref_passes(): void
    {
        $policy = BurnRatePolicy::googleSreDefault();
        $rules = new AlertRuleSet([
            new AlertRule('burn', Severity::Critical, AlertRuleKind::SloBurn, new SloBurnCondition($policy), sloRef: 'real-slo'),
        ]);
        $sloRegistry = new SloRegistry([new Slo('real-slo', 0.999, SloWindow::days(30), 'metric:x')]);

        $this->validator()->validate($rules, $sloRegistry);
        $this->addToAssertionCount(1);
    }

    public function test_slo_burn_without_slo_ref_rejected(): void
    {
        $policy = BurnRatePolicy::googleSreDefault();
        $rules = new AlertRuleSet([
            new AlertRule('burn', Severity::Critical, AlertRuleKind::SloBurn, new SloBurnCondition($policy)),
        ]);

        $this->expectException(AlertRuleValidationException::class);
        $this->validator()->validate($rules);
    }

    public function test_incoherent_for_duration_rejected_at_construction(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AlertRule('r', Severity::Warning, AlertRuleKind::ErrorRate, new ThresholdCondition(ThresholdOperator::GreaterThan, 0.1), forDuration: -5);
    }
}
