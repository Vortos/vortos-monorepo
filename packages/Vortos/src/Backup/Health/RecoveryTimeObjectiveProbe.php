<?php

declare(strict_types=1);

namespace Vortos\Backup\Health;

use Vortos\Backup\DR\ObjectiveStatus;
use Vortos\Backup\DR\RecoveryObjectivesInspector;
use Vortos\Health\Probe\HealthProbeInterface;
use Vortos\Health\Probe\ProbeKind;
use Vortos\Health\Probe\ProbeResult;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * Fails when point-in-time recovery would miss the declared RTO — now (`rto_breached`) or before the
 * next scheduled base backup resets the replay chain (`rto_breach_projected`).
 *
 * The projected case is the one that matters. Replay time grows with every archived segment until the
 * next base, so a cadence too slow for the write rate is visible from the first day of a cycle and a
 * recovery only misses at the end of it. Paging on the projection gives days of warning for a fault
 * that is a configuration change to fix.
 *
 * MONITORING kind for the same non-negotiable reason as {@see BackupFreshnessProbe}.
 */
#[AsDriver(self::NAME)]
final class RecoveryTimeObjectiveProbe implements HealthProbeInterface
{
    public const NAME = 'recovery-time-objective';

    public function __construct(private readonly RecoveryObjectivesInspector $inspector) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function kind(): ProbeKind
    {
        return ProbeKind::Monitoring;
    }

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create(['off_gate' => true, 'catalog_derived' => true]);
    }

    public function check(): ProbeResult
    {
        $start = microtime(true);
        $assessment = $this->inspector->recoveryTime();
        $latencyMs = round((microtime(true) - $start) * 1000, 2);

        return match ($assessment->status) {
            ObjectiveStatus::Met => ProbeResult::pass(self::NAME, $this->kind(), $latencyMs, $assessment->toDetail()),
            // Warn, never ProbeResult::skipped(): that is a Fail ('budget_exhausted') and would page.
            ObjectiveStatus::Undeclared => ProbeResult::warn(self::NAME, $this->kind(), $latencyMs, 'rto_undeclared', $assessment->toDetail()),
            ObjectiveStatus::Indeterminate => ProbeResult::warn(self::NAME, $this->kind(), $latencyMs, 'rto_indeterminate', $assessment->toDetail()),
            ObjectiveStatus::AtRisk => ProbeResult::fail(self::NAME, $this->kind(), $latencyMs, 'rto_breach_projected', $assessment->toDetail()),
            ObjectiveStatus::Breached => ProbeResult::fail(self::NAME, $this->kind(), $latencyMs, 'rto_breached', $assessment->toDetail()),
        };
    }
}
