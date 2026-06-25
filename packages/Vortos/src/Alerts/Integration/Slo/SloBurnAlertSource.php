<?php

declare(strict_types=1);

namespace Vortos\Alerts\Integration\Slo;

use DateTimeImmutable;
use Vortos\Alerts\AlertDispatcherInterface;
use Vortos\Alerts\DispatchResult;
use Vortos\Alerts\Rule\AlertRuleKind;
use Vortos\Alerts\Rule\AlertRuleSet;
use Vortos\Alerts\Rule\AlertRuleEvaluator;
use Vortos\Observability\Slo\SloRegistry;

/**
 * Reads Block 16 SLO defs + the configured burn-rate provider on the evaluator tick,
 * applies `slo_burn` rules (§3.7). Registered only when `vortos-observability`'s SLO
 * seam is present (it always is — Alerts requires Observability).
 */
final class SloBurnAlertSource
{
    public function __construct(
        private readonly SloRegistry $sloRegistry,
        private readonly AlertRuleSet $rules,
        private readonly AlertRuleEvaluator $evaluator,
        private readonly AlertDispatcherInterface $dispatcher,
        private readonly SloBurnRateProviderInterface $provider,
    ) {}

    /** @return list<DispatchResult> */
    public function tick(string $env, DateTimeImmutable $now): array
    {
        $results = [];

        foreach ($this->rules->all() as $rule) {
            if ($rule->kind !== AlertRuleKind::SloBurn || $rule->sloRef === null) {
                continue;
            }

            $slo = $this->sloRegistry->get($rule->sloRef);
            $sample = $this->provider->sample($slo);
            $event = $this->evaluator->evaluate($rule, $sample, $env, null, $now);

            if ($event !== null) {
                $results[] = $this->dispatcher->dispatch($event, $rule->routingOverride);
            }
        }

        return $results;
    }
}
