<?php

declare(strict_types=1);

namespace Vortos\Alerts\Integration\Health;

use Vortos\Alerts\Integration\AlertSourceInterface;
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
 *
 * DELEGATION. Alert evaluation runs on exactly one node — the scheduler — but a probe can only be
 * answered by the node that owns the dependency, and those are not always the same node. Calling
 * `check()` locally for every probe quietly assumes they are.
 *
 * That assumption paged on-call hourly for over a day here: the backup sidecar evaluates the alerts
 * and holds backup-only object-store credentials, deliberately denied the application's bucket, so
 * its S3 probe returned 403 and `object-store-unreachable` fired — while the application, the node
 * that actually serves uploads, reported the same store healthy the whole time. The alarm was
 * describing the credentials of the machine asking, not the health of the thing asked about.
 *
 * A delegated probe is therefore read from its owner over HTTP ({@see RemoteHealthProbeReader})
 * rather than re-answered locally. Everything else behaves exactly as before.
 */
final class HealthProbeAlertSource implements AlertSourceInterface
{
    /**
     * @param array<string, string> $delegatedProbes rule's `probe` label => the check name the
     *        owning node reports it under. The two differ in practice: a rule targets the registry
     *        key (`legacy-s3-object-store`, derived by BridgeLegacyHealthChecksPass) while the
     *        owner's health report is keyed by the check's own name() (`object_store`).
     */
    public function __construct(
        private readonly HealthProbeRegistry $probes,
        private readonly AlertRuleSet $rules,
        private readonly AlertRuleEvaluator $evaluator,
        private readonly AlertDispatcherInterface $dispatcher,
        private readonly ?RemoteHealthProbeReader $remote = null,
        private readonly array $delegatedProbes = [],
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
            if ($probeName === null) {
                continue;
            }

            $sample = isset($this->delegatedProbes[$probeName])
                ? $this->delegatedSample($probeName)
                : $this->localSample($probeName);

            // Null means this round could not assess the probe — the owner did not answer, or the
            // probe is not registered here. Skipped rather than guessed: an unreachable owner is an
            // outage the external uptime journey already covers, and inventing a verdict would
            // either double-page one incident or resolve an alert nothing actually looked at.
            if ($sample === null) {
                continue;
            }

            $event = $this->evaluator->evaluate($rule, $sample, $env, null, $now);
            if ($event !== null) {
                $results[] = $this->dispatcher->dispatch($event, $rule->routingOverride);
            }
        }

        return $results;
    }

    /**
     * The probe as this node sees it — unchanged behaviour for everything not delegated.
     */
    private function localSample(string $probeName): ?HealthProbeSample
    {
        if (!$this->probes->has($probeName)) {
            return null;
        }

        $probe = $this->probes->probe($probeName);
        $result = $probe->check();

        return new HealthProbeSample(
            failing: $result->status === ProbeStatus::Fail,
            probeName: $probe->name(),
            detail: $result->errorCode ?? '',
        );
    }

    /**
     * The probe as its OWNER reports it.
     *
     * Returns null — skip, do not guess — when the owner cannot be reached or does not report the
     * probe at all. A probe missing from the owner's report is a configuration mismatch between the
     * delegation map and the owner's checks, and reporting a dependency as failing on the strength
     * of a name that does not exist would be the same class of false page this delegation removes.
     */
    private function delegatedSample(string $probeName): ?HealthProbeSample
    {
        if ($this->remote === null) {
            return null;
        }

        $results = $this->remote->read();
        if ($results === null) {
            return null;
        }

        $remoteName = $this->delegatedProbes[$probeName];
        $result = $results[$remoteName] ?? null;

        if ($result === null) {
            return null;
        }

        return new HealthProbeSample(
            failing: $result->isFailing(),
            probeName: $probeName,
            detail: $result->detail,
        );
    }
}
