<?php

declare(strict_types=1);

namespace Vortos\Alerts\Integration\Health;

use DateTimeImmutable;
use Vortos\Alerts\AlertDispatcherInterface;
use Vortos\Alerts\DispatchResult;
use Vortos\Alerts\Rule\AlertRuleEvaluator;
use Vortos\Alerts\Rule\AlertRuleKind;
use Vortos\Alerts\Rule\AlertRuleSet;
use Vortos\Alerts\Rule\Sample\CertExpirySample;
use Vortos\Health\Probe\HealthProbeRegistry;

/**
 * Reads the cert-expiry probe's `days_until_expiry` detail (Block 18) for
 * `cert_near_expiry` rules — lead-time renewal alerting (§5.3), never a 3am page.
 * A handshake/inspection failure (no `days_until_expiry` in detail) is a different
 * alert (the probe's own readiness Fail surfaces via `health_probe_failing` if such
 * a rule is configured); this source only fires on a definitive expiry measurement.
 * Registered only when `vortos-health` is installed (class-existence guarded).
 */
final class CertExpiryAlertSource
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
            if ($rule->kind !== AlertRuleKind::CertNearExpiry) {
                continue;
            }

            $probeName = $rule->labels['probe'] ?? null;
            if ($probeName === null || !$this->probes->has($probeName)) {
                continue;
            }

            $probe = $this->probes->probe($probeName);
            $result = $probe->check();

            $days = $result->detail['days_until_expiry'] ?? null;
            if (!is_int($days)) {
                continue;
            }

            $sample = new CertExpirySample($days, $probe->name());

            $event = $this->evaluator->evaluate($rule, $sample, $env, null, $now);
            if ($event !== null) {
                $results[] = $this->dispatcher->dispatch($event, $rule->routingOverride);
            }
        }

        return $results;
    }
}
