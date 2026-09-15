<?php

declare(strict_types=1);

namespace Vortos\Alerts\Preflight;

use Vortos\Alerts\Rule\AlertRuleKind;
use Vortos\Alerts\Rule\AlertRuleSet;
use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightFinding;
use Vortos\Migration\Health\DatabaseRoleConformanceProbe;
use Vortos\OpsKit\Gate\GateDisposition;
use Vortos\Persistence\Access\DeclaredDatabaseRoles;

/**
 * Refuses a release that declares a least-privilege role model without the alert rule that pages when the live
 * database departs from it.
 *
 * A probe with no rule is measured and never heard — HealthProbeAlertSource only evaluates probes a
 * `health_probe_failing` rule names — and a role quietly granted SUPERUSER again, with nobody told, is the exact
 * defect the model exists to end.
 *
 * BLOCKING: this is configuration carried by the release being deployed, so the release is the remedy, and it can
 * never veto its own cure.
 */
final class DatabaseRoleConformanceAlertsCheck implements PreflightCheckInterface
{
    public function __construct(
        private readonly AlertRuleSet $rules,
        private readonly ?DeclaredDatabaseRoles $roles = null,
    ) {}

    public function id(): string
    {
        return 'alerts.database_role_conformance_covered';
    }

    public function category(): PreflightCategory
    {
        return PreflightCategory::Plan;
    }

    public function disposition(): GateDisposition
    {
        return GateDisposition::Blocking;
    }

    public function check(PreflightContext $context): PreflightFinding
    {
        if ($this->roles?->model() === null) {
            return PreflightFinding::skip($this->id(), $this->category(), 'no database role model declared');
        }

        foreach ($this->rules->all() as $rule) {
            if ($rule->kind === AlertRuleKind::HealthProbeFailing && ($rule->labels['probe'] ?? null) === DatabaseRoleConformanceProbe::NAME) {
                return PreflightFinding::pass($this->id(), $this->category(), 'Database role drift pages through an alert rule', DatabaseRoleConformanceProbe::NAME);
            }
        }

        return PreflightFinding::fail(
            $this->id(),
            $this->category(),
            'A least-privilege database role model is declared but nothing pages when the live database departs from it.',
            sprintf('No health_probe_failing alert rule names probe %s.', DatabaseRoleConformanceProbe::NAME),
            sprintf('Add a health_probe_failing rule with labels [\'probe\' => \'%s\'] to the alert rule config.', DatabaseRoleConformanceProbe::NAME),
        );
    }
}
