<?php

declare(strict_types=1);

namespace Vortos\Alerts\Integration\Health;

use DateTimeImmutable;
use Vortos\Alerts\AlertDispatcherInterface;
use Vortos\Alerts\DispatchResult;
use Vortos\Alerts\Event\AlertEvent;
use Vortos\Alerts\Event\AlertSource;
use Vortos\Alerts\Rule\AlertRuleKind;
use Vortos\Alerts\Rule\AlertRuleSet;
use Vortos\Alerts\Severity;
use Vortos\Health\Uptime\MonitorState;
use Vortos\Health\Uptime\UptimeMonitorRegistry;

/**
 * Reads each declared monitor's off-host {@see \Vortos\Health\Uptime\MonitorStatus}
 * for a matching `health_probe_failing`-style synthetic rule (§5.1, `rule->labels`
 * carry `monitor_id`): `Down` → `Critical`, `Degraded` → `Warning`. Severity is
 * state-driven (not the rule's declared severity) because the synthetic-prober
 * verdict already encodes how bad it is — unlike a generic threshold rule.
 *
 * `Unknown` is never reclassified as `Down` (§5 — a provider outage is not an app
 * outage); N consecutive `Unknown` ticks instead raise the "blind detector"
 * meta-alert (§5.4) — the failure mode that defeats most monitoring. Registered
 * only when `vortos-health` is installed (class-existence guarded).
 */
final class SyntheticUptimeAlertSource
{
    public function __construct(
        private readonly UptimeMonitorRegistry $monitors,
        private readonly string $monitorDriverKey,
        private readonly AlertRuleSet $rules,
        private readonly AlertDispatcherInterface $dispatcher,
        private readonly UptimeUnknownStreakStoreInterface $streaks,
        private readonly int $blindDetectorThreshold = 3,
    ) {}

    /** @return list<DispatchResult> */
    public function tick(string $env, DateTimeImmutable $now): array
    {
        $results = [];

        foreach ($this->rules->all() as $rule) {
            if ($rule->kind !== AlertRuleKind::HealthProbeFailing) {
                continue;
            }

            $monitorId = $rule->labels['monitor_id'] ?? null;
            if ($monitorId === null) {
                continue;
            }

            $status = $this->monitors->monitor($this->monitorDriverKey)->status($monitorId);

            if ($status->isUnknown()) {
                $streak = $this->streaks->increment($monitorId);

                if ($streak >= $this->blindDetectorThreshold) {
                    $results[] = $this->dispatcher->dispatch(
                        $this->blindDetectorEvent($rule->labels, $monitorId, $streak, $env, $now),
                        $rule->routingOverride,
                    );
                }

                continue;
            }

            $this->streaks->reset($monitorId);

            if ($status->state === MonitorState::Up) {
                continue;
            }

            $severity = $status->state === MonitorState::Down ? Severity::Critical : Severity::Warning;

            $results[] = $this->dispatcher->dispatch(
                $this->statusEvent($rule->labels, $monitorId, $status->state, $severity, $env, $now),
                $rule->routingOverride,
            );
        }

        return $results;
    }

    /** @param array<string, string> $labels */
    private function statusEvent(array $labels, string $monitorId, MonitorState $state, Severity $severity, string $env, DateTimeImmutable $now): AlertEvent
    {
        return AlertEvent::scrubbed(
            ruleId: 'synthetic.' . $monitorId,
            severity: $severity,
            title: sprintf('Synthetic uptime check %s: %s', $state->value, $monitorId),
            summary: sprintf('External synthetic monitor "%s" reports %s.', $monitorId, $state->value),
            source: AlertSource::Synthetic,
            env: $env,
            tenantId: null,
            labels: $labels,
            annotations: [],
            links: [],
            occurredAt: $now,
        );
    }

    /** @param array<string, string> $labels */
    private function blindDetectorEvent(array $labels, string $monitorId, int $streak, string $env, DateTimeImmutable $now): AlertEvent
    {
        return AlertEvent::scrubbed(
            ruleId: 'synthetic.' . $monitorId . '.blind-detector',
            severity: Severity::Warning,
            title: sprintf('Uptime monitor "%s" detector is blind', $monitorId),
            summary: sprintf(
                'No definitive status for %d consecutive ticks — the external prober itself may be unreachable.',
                $streak,
            ),
            source: AlertSource::Synthetic,
            env: $env,
            tenantId: null,
            labels: $labels,
            annotations: [],
            links: [],
            occurredAt: $now,
        );
    }
}
