<?php

declare(strict_types=1);

namespace Vortos\Alerts\Rule;

use Vortos\Alerts\Rule\Condition\CertExpiryCondition;
use Vortos\Alerts\Rule\Condition\NoCondition;
use Vortos\Alerts\Rule\Condition\ResourceCondition;
use Vortos\Alerts\Rule\Condition\SloBurnCondition;
use Vortos\Alerts\Rule\Condition\ThresholdCondition;
use Vortos\Observability\Slo\SloRegistry;

/**
 * Config-time validation (§3.2 / §15): thresholds in range (delegated to each
 * condition's own constructor), `forDuration` coherent, no duplicate ids — and,
 * crucially, a `sloRef` resolves against the Block 16 {@see SloRegistry}. Invalid
 * config never silently never-fires; it throws here, surfaced by
 * `alerts:rules:validate` and the `deploy:doctor` check.
 */
final class AlertRuleValidator
{
    /**
     * @throws AlertRuleValidationException when any rule is invalid
     */
    public function validate(AlertRuleSet $rules, ?SloRegistry $sloRegistry = null): void
    {
        $violations = [];
        $seenIds = [];

        foreach ($rules->all() as $rule) {
            if (isset($seenIds[$rule->id])) {
                $violations[] = sprintf('duplicate rule id "%s"', $rule->id);
            }
            $seenIds[$rule->id] = true;

            $violations = [...$violations, ...$this->validateOne($rule, $sloRegistry)];
        }

        if ($violations !== []) {
            throw new AlertRuleValidationException($violations);
        }
    }

    /** @return list<string> */
    private function validateOne(AlertRule $rule, ?SloRegistry $sloRegistry): array
    {
        $violations = [];

        $expectedCondition = match ($rule->kind) {
            AlertRuleKind::ErrorRate, AlertRuleKind::P95Latency, AlertRuleKind::QueueLag => ThresholdCondition::class,
            AlertRuleKind::SloBurn => SloBurnCondition::class,
            AlertRuleKind::HealthProbeFailing, AlertRuleKind::BackupFailed => NoCondition::class,
            AlertRuleKind::ResourceExhaustion => ResourceCondition::class,
            AlertRuleKind::CertNearExpiry => CertExpiryCondition::class,
        };

        if (!$rule->condition instanceof $expectedCondition) {
            $violations[] = sprintf(
                'rule "%s": kind "%s" requires condition type %s, got %s',
                $rule->id,
                $rule->kind->value,
                $expectedCondition,
                $rule->condition::class,
            );
        }

        if ($rule->kind === AlertRuleKind::SloBurn) {
            if ($rule->sloRef === null || $rule->sloRef === '') {
                $violations[] = sprintf('rule "%s": kind "slo_burn" requires a sloRef', $rule->id);
            } elseif ($sloRegistry !== null && !$sloRegistry->has($rule->sloRef)) {
                $violations[] = sprintf('rule "%s": dangling sloRef "%s" (no such SLO registered)', $rule->id, $rule->sloRef);
            }
        }

        return $violations;
    }
}
