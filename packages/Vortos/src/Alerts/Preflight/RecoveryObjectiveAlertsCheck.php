<?php

declare(strict_types=1);

namespace Vortos\Alerts\Preflight;

use Vortos\Alerts\Rule\AlertRuleKind;
use Vortos\Alerts\Rule\AlertRuleSet;
use Vortos\Backup\Config\BackupConfigLoader;
use Vortos\Backup\Health\RecoveryPointObjectiveProbe;
use Vortos\Backup\Health\RecoveryTimeObjectiveProbe;
use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightFinding;
use Vortos\OpsKit\Gate\GateDisposition;

/**
 * Refuses a release that declares recovery objectives without the alert rules that page on them.
 *
 * A probe with no rule is measured and never heard: HealthProbeAlertSource only evaluates probes a
 * `health_probe_failing` rule names. That is precisely how the backup freshness signal once stayed
 * dark — the probe and the alert pipeline both existed and nothing joined them — and an objective
 * that is enforced only when someone remembers to add the rule is the original FB-66 defect again.
 *
 * BLOCKING: this is configuration carried by the release being deployed, so the release is the
 * remedy, and it can never veto its own cure.
 */
final class RecoveryObjectiveAlertsCheck implements PreflightCheckInterface
{
    /** @var list<string> the probes every declared objective must page through */
    public const REQUIRED_PROBES = [RecoveryPointObjectiveProbe::NAME, RecoveryTimeObjectiveProbe::NAME];

    public function __construct(
        private readonly AlertRuleSet $rules,
        private readonly ?BackupConfigLoader $backupConfig = null,
    ) {}

    public function id(): string
    {
        return 'alerts.recovery_objectives_covered';
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
        if ($this->backupConfig?->recoveryObjectivesOrNull() === null) {
            return PreflightFinding::skip($this->id(), $this->category(), 'no recovery objectives declared');
        }

        $covered = [];
        foreach ($this->rules->all() as $rule) {
            if ($rule->kind === AlertRuleKind::HealthProbeFailing && isset($rule->labels['probe'])) {
                $covered[$rule->labels['probe']] = true;
            }
        }

        $missing = array_values(array_filter(
            self::REQUIRED_PROBES,
            static fn (string $probe): bool => !isset($covered[$probe]),
        ));

        if ($missing !== []) {
            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                'Recovery objectives are declared but nothing pages when they are missed.',
                sprintf('No health_probe_failing alert rule names probe(s): %s.', implode(', ', $missing)),
                'Add a health_probe_failing rule with labels [\'probe\' => \'<name>\'] for each to the alert rule config.',
            );
        }

        return PreflightFinding::pass(
            $this->id(),
            $this->category(),
            'recovery objectives page through alert rules',
            implode(', ', self::REQUIRED_PROBES),
        );
    }
}
