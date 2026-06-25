<?php

declare(strict_types=1);

namespace Vortos\Alerts\Integration\Health;

use DateTimeImmutable;
use Vortos\Alerts\AlertDispatcherInterface;
use Vortos\Alerts\DispatchResult;
use Vortos\Alerts\Rule\AlertRuleKind;
use Vortos\Alerts\Rule\AlertRuleEvaluator;
use Vortos\Alerts\Rule\AlertRuleSet;
use Vortos\Alerts\Rule\Sample\HealthProbeSample;
use Vortos\Health\Probe\HealthProbeRegistry;
use Vortos\Health\Probe\ProbeStatus;

/**
 * Reads {@see HealthProbeRegistry} results off the readiness path (§3.7) for
 * `health_probe_failing` rules. A rule names the probe via its `probe` label.
 * Registered only when `vortos-health` is installed (class-existence guarded).
 */
final class HealthProbeAlertSource
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
            if ($rule->kind !== AlertRuleKind::HealthProbeFailing) {
                continue;
            }

            $probeName = $rule->labels['probe'] ?? null;
            if ($probeName === null || !$this->probes->has($probeName)) {
                continue;
            }

            $probe = $this->probes->probe($probeName);
            $result = $probe->check();

            $sample = new HealthProbeSample(
                failing: $result->status === ProbeStatus::Fail,
                probeName: $probe->name(),
                detail: $result->errorCode ?? '',
            );

            $event = $this->evaluator->evaluate($rule, $sample, $env, null, $now);
            if ($event !== null) {
                $results[] = $this->dispatcher->dispatch($event, $rule->routingOverride);
            }
        }

        return $results;
    }
}
