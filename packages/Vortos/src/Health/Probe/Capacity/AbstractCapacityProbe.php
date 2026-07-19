<?php

declare(strict_types=1);

namespace Vortos\Health\Probe\Capacity;

use InvalidArgumentException;
use Vortos\Health\Probe\Capability\HealthCapability;
use Vortos\Health\Probe\Capacity\CapacityReader\CapacityReaderInterface;
use Vortos\Health\Probe\HealthProbeInterface;
use Vortos\Health\Probe\ProbeKind;
use Vortos\Health\Probe\ProbeResult;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * Tri-state capacity probe: `< warnPct` passes, `[warnPct, criticalPct)` degrades,
 * `>= criticalPct` fails (§3 capacity). Never on the liveness hot path.
 *
 * The probe's KIND is configurable (default {@see ProbeKind::Readiness}) precisely because gating
 * readiness on capacity is only safe when there is somewhere to drain TO. In a single-active-replica
 * topology (e.g. blue/green where exactly one color serves) a capacity-driven readiness failure
 * ejects the ONLY upstream — turning a transient CPU/memory spike (a fresh color's warmup, or a
 * steady-state burst) into a total outage instead of shedding load to a peer. Such deployments set
 * the kind to {@see ProbeKind::Monitoring}: capacity is still measured, surfaced, and alertable — it
 * just no longer decides whether the sole node receives traffic.
 */
abstract class AbstractCapacityProbe implements HealthProbeInterface
{
    public function __construct(
        protected readonly CapacityReaderInterface $reader,
        protected readonly float $warnPct = 85.0,
        protected readonly float $criticalPct = 95.0,
        private readonly ProbeKind $kind = ProbeKind::Readiness,
    ) {
        if ($warnPct <= 0.0 || $warnPct > 100.0) {
            throw new InvalidArgumentException('Capacity probe warnPct must be in (0, 100].');
        }
        if ($criticalPct <= 0.0 || $criticalPct > 100.0) {
            throw new InvalidArgumentException('Capacity probe criticalPct must be in (0, 100].');
        }
        if ($warnPct >= $criticalPct) {
            throw new InvalidArgumentException('Capacity probe warnPct must be < criticalPct.');
        }
        if ($kind === ProbeKind::Liveness) {
            // Capacity must never gate liveness — a loaded-but-alive process must not be killed.
            throw new InvalidArgumentException('Capacity probe kind must not be Liveness.');
        }
    }

    /** The undecorated reading from the reader seam, or null when undeterminable. */
    abstract protected function read(): ?float;

    public function kind(): ProbeKind
    {
        return $this->kind;
    }

    public function check(): ProbeResult
    {
        $start = microtime(true);
        $usedPct = $this->read();
        $latencyMs = (microtime(true) - $start) * 1000.0;

        if ($usedPct === null) {
            return ProbeResult::warn($this->name(), $this->kind(), $latencyMs, 'capacity_unreadable');
        }

        $detail = ['used_pct' => round($usedPct, 2)];

        if ($usedPct >= $this->criticalPct) {
            return ProbeResult::fail($this->name(), $this->kind(), $latencyMs, 'capacity_critical', $detail);
        }

        if ($usedPct >= $this->warnPct) {
            return ProbeResult::warn($this->name(), $this->kind(), $latencyMs, 'capacity_warn', $detail);
        }

        return ProbeResult::pass($this->name(), $this->kind(), $latencyMs, $detail);
    }

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            HealthCapability::DependencyCheck->value => false,
            HealthCapability::BoundedLatency->value => true,
            HealthCapability::ReadOnly->value => true,
            HealthCapability::ProcessLocal->value => true,
        ]);
    }
}
