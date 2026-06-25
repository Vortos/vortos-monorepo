<?php

declare(strict_types=1);

namespace Vortos\Health\Preflight;

use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightFinding;
use Vortos\Health\Probe\HealthProbeRegistry;
use Vortos\Health\Uptime\Capability\UptimeCapability;
use Vortos\Health\Uptime\UptimeMonitorRegistry;

/**
 * §12.4 / §6.1 — refuses a prod deploy that does not have three independent failure
 * detectors actually configured: in-app probes, the dead-man heartbeat, and an
 * external synthetic prober. Registered only when `vortos-deploy` is installed
 * (interface-existence guarded), mirroring `Vortos\Alerts\Preflight\AlertRulesDoctorCheck`.
 *
 * Non-prod environments are not fail-closed (the framework's {@see \Vortos\Deploy\Preflight\PreflightStatus}
 * has no advisory "warning" state — every check is Pass/Fail/Skip — so a gap outside
 * prod surfaces as a `Pass` with an explanatory summary rather than blocking).
 */
final class DetectorIndependenceDoctorCheck implements PreflightCheckInterface
{
    private const PROD_ENV_NAMES = ['prod', 'production'];

    public function __construct(
        private readonly HealthProbeRegistry $probes,
        private readonly ?UptimeMonitorRegistry $uptimeMonitors,
        private readonly string $configuredUptimeDriverKey,
        private readonly bool $heartbeatConfigured,
    ) {}

    public function id(): string
    {
        return 'health.detector_independence';
    }

    public function category(): PreflightCategory
    {
        return PreflightCategory::Plan;
    }

    public function check(PreflightContext $context): PreflightFinding
    {
        $detectors = $this->configuredDetectors();
        $count = count($detectors);
        $summary = $count === 0
            ? 'No independent failure detectors configured.'
            : sprintf('%d of 3 independent failure detectors configured: %s.', $count, implode(', ', $detectors));

        if ($count >= 3) {
            return PreflightFinding::pass($this->id(), $this->category(), $summary);
        }

        if (!in_array($context->environment->value, self::PROD_ENV_NAMES, true)) {
            return PreflightFinding::pass($this->id(), $this->category(), $summary . ' (non-prod: not fail-closed)');
        }

        return PreflightFinding::fail(
            $this->id(),
            $this->category(),
            $summary,
            remediation: 'Wire the dead-man heartbeat (vortos-observability) and a real external synthetic '
                . 'monitor driver (e.g. "betterstack") declaring SyntheticJourney — a dead host and its own '
                . 'monitoring being dead simultaneously is the failure mode §12.4 exists to catch.',
        );
    }

    /** @return list<string> */
    private function configuredDetectors(): array
    {
        $detectors = [];

        if ($this->probes->allProbes() !== []) {
            $detectors[] = 'in-app probes';
        }

        if ($this->heartbeatConfigured) {
            $detectors[] = 'dead-man heartbeat';
        }

        if ($this->hasRealSyntheticProber()) {
            $detectors[] = 'external synthetic prober';
        }

        return $detectors;
    }

    private function hasRealSyntheticProber(): bool
    {
        if ($this->uptimeMonitors === null || $this->configuredUptimeDriverKey === 'null') {
            return false;
        }

        if (!$this->uptimeMonitors->has($this->configuredUptimeDriverKey)) {
            return false;
        }

        return $this->uptimeMonitors->monitor($this->configuredUptimeDriverKey)
            ->capabilities()
            ->supports(UptimeCapability::SyntheticJourney);
    }
}
