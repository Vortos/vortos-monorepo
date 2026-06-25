<?php

declare(strict_types=1);

namespace Vortos\Health\Preflight;

use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightFinding;
use Vortos\Health\Probe\Capability\HealthCapability;
use Vortos\Health\Probe\HealthProbeRegistry;
use Vortos\Health\Probe\ProbeKind;

/**
 * Fail-closed boot gate for the invariant {@see \Vortos\Health\Tests\Architecture\LivenessIndependenceTest}
 * checks at test-time only: a `Liveness` probe must never declare `dependency_check=true`
 * — a liveness probe that depends on a downstream service causes restart storms during
 * an outage (the dependency goes down, every replica's liveness fails, the orchestrator
 * restarts all of them, none of that fixes the dependency). Running this against the
 * actually-configured probe set on every `deploy:doctor` invocation closes the gap the
 * unit test cannot: a misconfigured production container fails the gate before it ships,
 * not just before it merges. Registered only when `vortos-deploy` is installed
 * (interface-existence guarded), mirroring {@see DetectorIndependenceDoctorCheck}.
 */
final class LivenessIndependenceDoctorCheck implements PreflightCheckInterface
{
    public function __construct(
        private readonly HealthProbeRegistry $probes,
    ) {}

    public function id(): string
    {
        return 'health.liveness_independence';
    }

    public function category(): PreflightCategory
    {
        return PreflightCategory::Plan;
    }

    public function check(PreflightContext $context): PreflightFinding
    {
        $offenders = [];

        foreach ($this->probes->probesOfKind(ProbeKind::Liveness) as $probe) {
            if ($probe->capabilities()->supports(HealthCapability::DependencyCheck)) {
                $offenders[] = $probe->name();
            }
        }

        if ($offenders === []) {
            return PreflightFinding::pass(
                $this->id(),
                $this->category(),
                'No liveness probe declares dependency_check=true.',
            );
        }

        return PreflightFinding::fail(
            $this->id(),
            $this->category(),
            sprintf(
                '%d liveness probe(s) declare dependency_check=true: %s.',
                count($offenders),
                implode(', ', $offenders),
            ),
            remediation: 'A liveness probe must be process-local only. Move the dependency check to a '
                . 'Readiness probe instead — a liveness probe that depends on a downstream service causes '
                . 'restart storms during an outage.',
        );
    }
}
