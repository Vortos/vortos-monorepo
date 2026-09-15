<?php

declare(strict_types=1);

namespace Vortos\Alerts\Preflight;

use Vortos\Alerts\Rule\AlertRuleKind;
use Vortos\Alerts\Rule\AlertRuleSet;
use Vortos\Backup\Config\BackupConfigLoader;
use Vortos\Backup\Health\PostgresConfigDriftProbe;
use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightFinding;
use Vortos\OpsKit\Gate\GateDisposition;

/**
 * Refuses a release that declares the PostgreSQL configuration file without the alert rule that pages when
 * the running cluster departs from it (RC-5).
 *
 * A probe with no rule is measured and never heard — HealthProbeAlertSource only evaluates probes a
 * `health_probe_failing` rule names — and a hand-applied ALTER SYSTEM that nobody is told about is the
 * exact defect the baked configuration exists to end.
 *
 * BLOCKING: this is configuration carried by the release being deployed, so the release is the remedy, and
 * it can never veto its own cure.
 */
final class PostgresConfigDriftAlertsCheck implements PreflightCheckInterface
{
    public function __construct(
        private readonly AlertRuleSet $rules,
        private readonly ?BackupConfigLoader $backupConfig = null,
    ) {}

    public function id(): string
    {
        return 'alerts.postgres_config_drift_covered';
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
        if ($this->backupConfig?->postgresConfigFileOrNull() === null) {
            return PreflightFinding::skip($this->id(), $this->category(), 'no PostgreSQL configuration file declared');
        }

        foreach ($this->rules->all() as $rule) {
            if ($rule->kind === AlertRuleKind::HealthProbeFailing && ($rule->labels['probe'] ?? null) === PostgresConfigDriftProbe::NAME) {
                return PreflightFinding::pass($this->id(), $this->category(), 'PostgreSQL configuration drift pages through an alert rule', PostgresConfigDriftProbe::NAME);
            }
        }

        return PreflightFinding::fail(
            $this->id(),
            $this->category(),
            'A PostgreSQL configuration file is declared but nothing pages when the running cluster departs from it.',
            sprintf('No health_probe_failing alert rule names probe %s.', PostgresConfigDriftProbe::NAME),
            sprintf('Add a health_probe_failing rule with labels [\'probe\' => \'%s\'] to the alert rule config.', PostgresConfigDriftProbe::NAME),
        );
    }
}
