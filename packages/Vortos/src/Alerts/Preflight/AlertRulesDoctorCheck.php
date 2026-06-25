<?php

declare(strict_types=1);

namespace Vortos\Alerts\Preflight;

use Vortos\Alerts\Rule\AlertRuleSet;
use Vortos\Alerts\Rule\AlertRuleValidationException;
use Vortos\Alerts\Rule\AlertRuleValidator;
use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightFinding;
use Vortos\Observability\Slo\SloRegistry;

/**
 * Implements the Deploy doctor seam (§3.2 DoD): a typo'd alert rule — a bad
 * threshold, a duplicate id, a dangling `sloRef` — fails `deploy:doctor`, never by
 * silently never firing at 3am. Registered only when `vortos-deploy` is installed
 * (class-existence guarded; this class itself is never autoloaded otherwise).
 */
final class AlertRulesDoctorCheck implements PreflightCheckInterface
{
    public function __construct(
        private readonly AlertRuleSet $rules,
        private readonly AlertRuleValidator $validator,
        private readonly ?SloRegistry $sloRegistry = null,
    ) {}

    public function id(): string
    {
        return 'alerts.rules_valid';
    }

    public function category(): PreflightCategory
    {
        return PreflightCategory::Plan;
    }

    public function check(PreflightContext $context): PreflightFinding
    {
        try {
            $this->validator->validate($this->rules, $this->sloRegistry);
        } catch (AlertRuleValidationException $e) {
            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                sprintf('%d alert rule(s) failed validation', count($e->violations)),
                implode('; ', $e->violations),
                'Fix the invalid alert rule(s) in the declared alert rule config before deploying.',
            );
        }

        return PreflightFinding::pass(
            $this->id(),
            $this->category(),
            'all alert rules are valid',
            sprintf('%d rule(s) checked', count($this->rules->all())),
        );
    }
}
