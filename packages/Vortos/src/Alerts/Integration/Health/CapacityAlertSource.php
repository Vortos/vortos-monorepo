<?php

declare(strict_types=1);

namespace Vortos\Alerts\Integration\Health;

use DateTimeImmutable;
use Vortos\Alerts\AlertDispatcherInterface;
use Vortos\Alerts\DispatchResult;
use Vortos\Alerts\Rule\AlertRuleEvaluator;
use Vortos\Alerts\Rule\AlertRuleKind;
use Vortos\Alerts\Rule\AlertRuleSet;
use Vortos\Alerts\Rule\Sample\ResourceSample;
use Vortos\Health\Probe\HealthProbeRegistry;

/**
 * Reads capacity probe readings (disk/RAM/CPU, Block 18) off the readiness path for
 * `resource_exhaustion` rules — the same sample feeds readiness (drains the node)
 * and this alert path (§5.2), with no double sampling: this source reads the probe's
 * own `check()` result, it does not re-sample the host. Registered only when
 * `vortos-health` is installed (class-existence guarded), mirroring
 * {@see HealthProbeAlertSource}.
 */
final class CapacityAlertSource
{
    public function __construct(
        private readonly HealthProbeRegistry $probes,
        private readonly AlertRuleSet $rules,
        private readonly AlertRuleEvaluator $evaluator,
        private readonly AlertDispatcherInterface $dispatcher,
    ) {}

    /** @return list<DispatchResult> */
    public function tick(string $env, DateTimeImmutable $now): array
    {
        $results = [];

        foreach ($this->rules->all() as $rule) {
            if ($rule->kind !== AlertRuleKind::ResourceExhaustion) {
                continue;
            }

            $probeName = $rule->labels['probe'] ?? null;
            if ($probeName === null || !$this->probes->has($probeName)) {
                continue;
            }

            $probe = $this->probes->probe($probeName);
            $result = $probe->check();

            $usedPct = $result->detail['used_pct'] ?? null;
            if (!is_numeric($usedPct)) {
                // capacity_unreadable this tick — nothing definitive to evaluate.
                continue;
            }

            $sample = new ResourceSample((float) $usedPct, $probe->name());

            $event = $this->evaluator->evaluate($rule, $sample, $env, null, $now);
            if ($event !== null) {
                $results[] = $this->dispatcher->dispatch($event, $rule->routingOverride);
            }
        }

        return $results;
    }
}
