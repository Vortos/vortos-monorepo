<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Unit\Rule;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Alerts\Rule\AlertRule;
use Vortos\Alerts\Rule\AlertRuleEvaluator;
use Vortos\Alerts\Rule\AlertRuleKind;
use Vortos\Alerts\Rule\Condition\CertExpiryCondition;
use Vortos\Alerts\Rule\Condition\NoCondition;
use Vortos\Alerts\Rule\Condition\ResourceCondition;
use Vortos\Alerts\Rule\Condition\SloBurnCondition;
use Vortos\Alerts\Rule\Condition\ThresholdCondition;
use Vortos\Alerts\Rule\Condition\ThresholdOperator;
use Vortos\Alerts\Rule\Sample\BackupFailedSample;
use Vortos\Alerts\Rule\Sample\BurnRateSample;
use Vortos\Alerts\Rule\Sample\CertExpirySample;
use Vortos\Alerts\Rule\Sample\HealthProbeSample;
use Vortos\Alerts\Rule\Sample\ResourceSample;
use Vortos\Alerts\Rule\Sample\ThresholdSample;
use Vortos\Alerts\Severity;
use Vortos\Observability\Slo\BurnRatePolicy;

final class AlertRuleEvaluatorTest extends TestCase
{
    private function evaluator(): AlertRuleEvaluator
    {
        return new AlertRuleEvaluator();
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-01-01T00:00:00+00:00');
    }

    public function test_threshold_rule_fires_at_boundary(): void
    {
        $rule = new AlertRule('err', Severity::Critical, AlertRuleKind::ErrorRate, new ThresholdCondition(ThresholdOperator::GreaterThanOrEqual, 0.05));

        $fired = $this->evaluator()->evaluate($rule, new ThresholdSample(0.05), 'prod', null, $this->now());
        $notFired = $this->evaluator()->evaluate($rule, new ThresholdSample(0.049), 'prod', null, $this->now());

        self::assertNotNull($fired);
        self::assertNull($notFired);
    }

    public function test_slo_burn_requires_both_fast_and_slow(): void
    {
        $rule = new AlertRule('burn', Severity::Critical, AlertRuleKind::SloBurn, new SloBurnCondition(BurnRatePolicy::googleSreDefault()), sloRef: 's1');

        $bothFast = $this->evaluator()->evaluate($rule, new BurnRateSample(20.0, 0.0), 'prod', null, $this->now());
        $bothPage = $this->evaluator()->evaluate($rule, new BurnRateSample(20.0, 10.0), 'prod', null, $this->now());

        self::assertNull($bothFast, 'fast alone (slow not page-worthy) must not fire');
        self::assertNotNull($bothPage);
    }

    public function test_health_probe_failing_fires_only_when_failing(): void
    {
        $rule = new AlertRule('probe', Severity::Critical, AlertRuleKind::HealthProbeFailing, new NoCondition());

        $healthy = $this->evaluator()->evaluate($rule, new HealthProbeSample(false, 'db'), 'prod', null, $this->now());
        $failing = $this->evaluator()->evaluate($rule, new HealthProbeSample(true, 'db', 'connection refused'), 'prod', null, $this->now());

        self::assertNull($healthy);
        self::assertNotNull($failing);
    }

    public function test_resource_exhaustion_85_warns_95_criticals(): void
    {
        $rule = new AlertRule('res', Severity::Warning, AlertRuleKind::ResourceExhaustion, new ResourceCondition());

        $ok = $this->evaluator()->evaluate($rule, new ResourceSample(84.9, 'disk'), 'prod', null, $this->now());
        $warn = $this->evaluator()->evaluate($rule, new ResourceSample(85.0, 'disk'), 'prod', null, $this->now());
        $critical = $this->evaluator()->evaluate($rule, new ResourceSample(95.0, 'disk'), 'prod', null, $this->now());

        self::assertNull($ok);
        self::assertNotNull($warn);
        self::assertSame(Severity::Warning, $warn->severity);
        self::assertNotNull($critical);
        self::assertSame(Severity::Critical, $critical->severity);
    }

    public function test_cert_near_expiry_14_7_1_day_lead(): void
    {
        $rule = new AlertRule('cert', Severity::Warning, AlertRuleKind::CertNearExpiry, new CertExpiryCondition());

        $safe = $this->evaluator()->evaluate($rule, new CertExpirySample(20, 'example.com'), 'prod', null, $this->now());
        $warnAt14 = $this->evaluator()->evaluate($rule, new CertExpirySample(14, 'example.com'), 'prod', null, $this->now());
        $warnAt7 = $this->evaluator()->evaluate($rule, new CertExpirySample(7, 'example.com'), 'prod', null, $this->now());
        $criticalAt1 = $this->evaluator()->evaluate($rule, new CertExpirySample(1, 'example.com'), 'prod', null, $this->now());

        self::assertNull($safe);
        self::assertSame(Severity::Warning, $warnAt14->severity);
        self::assertSame(Severity::Warning, $warnAt7->severity);
        self::assertSame(Severity::Critical, $criticalAt1->severity);
    }

    public function test_backup_failed_fires_only_when_failed(): void
    {
        $rule = new AlertRule('backup', Severity::Critical, AlertRuleKind::BackupFailed, new NoCondition());

        $ok = $this->evaluator()->evaluate($rule, new BackupFailedSample(false), 'prod', null, $this->now());
        $failed = $this->evaluator()->evaluate($rule, new BackupFailedSample(true, 'disk full'), 'prod', null, $this->now());

        self::assertNull($ok);
        self::assertNotNull($failed);
    }

    public function test_mismatched_sample_type_throws(): void
    {
        $rule = new AlertRule('err', Severity::Critical, AlertRuleKind::ErrorRate, new ThresholdCondition(ThresholdOperator::GreaterThan, 0.05));

        $this->expectException(\InvalidArgumentException::class);
        $this->evaluator()->evaluate($rule, new BackupFailedSample(true), 'prod', null, $this->now());
    }
}
