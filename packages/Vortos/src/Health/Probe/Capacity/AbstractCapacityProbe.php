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
 * Tri-state capacity probe: `< warnPct` passes, `[warnPct, criticalPct)` degrades
 * readiness (drains the node, never fails liveness), `>= criticalPct` fails (§3
 * capacity). Readiness-only — never on the liveness hot path.
 */
abstract class AbstractCapacityProbe implements HealthProbeInterface
{
    public function __construct(
        protected readonly CapacityReaderInterface $reader,
        protected readonly float $warnPct = 85.0,
        protected readonly float $criticalPct = 95.0,
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
    }

    /** The undecorated reading from the reader seam, or null when undeterminable. */
    abstract protected function read(): ?float;

    public function kind(): ProbeKind
    {
        return ProbeKind::Readiness;
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
