<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Unit\Preflight;

use PHPUnit\Framework\TestCase;
use Vortos\Alerts\Preflight\DatabaseRoleConformanceAlertsCheck;
use Vortos\Alerts\Rule\AlertRule;
use Vortos\Alerts\Rule\AlertRuleKind;
use Vortos\Alerts\Rule\AlertRuleSet;
use Vortos\Alerts\Rule\Condition\NoCondition;
use Vortos\Alerts\Severity;
use Vortos\Deploy\Preflight\PreflightStatus;
use Vortos\Deploy\Tests\Fixtures\PreflightTestFactory;
use Vortos\OpsKit\Gate\GateDisposition;
use Vortos\Persistence\Access\DatabaseRoleModel;
use Vortos\Persistence\Access\DatabaseRoleName;
use Vortos\Persistence\Access\DeclaredDatabaseRoles;

/** A least-privilege role model nothing pages on is measured and never heard. */
final class DatabaseRoleConformanceAlertsCheckTest extends TestCase
{
    use PreflightTestFactory;

    private static function declared(): DeclaredDatabaseRoles
    {
        return new DeclaredDatabaseRoles((new DatabaseRoleModel(
            'app',
            ['public'],
            new DatabaseRoleName('boot'),
            new DatabaseRoleName('app_owner'),
            new DatabaseRoleName('app_runtime'),
            null,
            [],
        ))->toArray());
    }

    private static function rule(AlertRuleKind $kind, string $probe): AlertRule
    {
        return new AlertRule('rule-' . $probe, Severity::Critical, $kind, new NoCondition(), labels: ['probe' => $probe]);
    }

    public function test_a_declared_model_without_a_rule_refuses_the_release(): void
    {
        $check = new DatabaseRoleConformanceAlertsCheck(new AlertRuleSet([self::rule(AlertRuleKind::HealthProbeFailing, 'backup-freshness')]), self::declared());

        $finding = $check->check($this->context());

        self::assertSame(PreflightStatus::Fail, $finding->status);
        self::assertStringContainsString('database-role-conformance', $finding->detail);
        self::assertSame(GateDisposition::Blocking, $check->disposition());
        self::assertSame('alerts.database_role_conformance_covered', $check->id());
    }

    public function test_a_rule_of_the_wrong_kind_does_not_count(): void
    {
        $rules = new AlertRuleSet([self::rule(AlertRuleKind::BackupFailed, 'database-role-conformance')]);

        self::assertSame(PreflightStatus::Fail, (new DatabaseRoleConformanceAlertsCheck($rules, self::declared()))->check($this->context())->status);
    }

    public function test_the_rule_passes_and_no_model_skips(): void
    {
        $rules = new AlertRuleSet([self::rule(AlertRuleKind::HealthProbeFailing, 'database-role-conformance')]);

        self::assertSame(PreflightStatus::Pass, (new DatabaseRoleConformanceAlertsCheck($rules, self::declared()))->check($this->context())->status);
        self::assertSame(PreflightStatus::Skip, (new DatabaseRoleConformanceAlertsCheck(new AlertRuleSet([]), new DeclaredDatabaseRoles(null)))->check($this->context())->status);
        self::assertSame(PreflightStatus::Skip, (new DatabaseRoleConformanceAlertsCheck(new AlertRuleSet([])))->check($this->context())->status);
    }
}
