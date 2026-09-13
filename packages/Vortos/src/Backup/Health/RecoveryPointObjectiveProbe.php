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
 * Fails when more written WAL is unarchived than the declared RPO allows.
 *
 * MONITORING kind for the same non-negotiable reason as {@see BackupFreshnessProbe}: a breached
 * objective means page someone, never stop serving traffic.
 */
#[AsDriver(self::NAME)]
final class RecoveryPointObjectiveProbe implements HealthProbeInterface
{
    public const NAME = 'recovery-point-objective';

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
        return CapabilityDescriptor::create(['off_gate' => true, 'cluster_derived' => true]);
    }

    public function check(): ProbeResult
    {
        $start = microtime(true);
        $assessment = $this->inspector->recoveryPoint();
        $latencyMs = round((microtime(true) - $start) * 1000, 2);

        return match ($assessment->status) {
            ObjectiveStatus::Met => ProbeResult::pass(self::NAME, $this->kind(), $latencyMs, $assessment->toDetail()),
            // Warn, never ProbeResult::skipped(): that is a Fail ('budget_exhausted') and would page an
            // installation for having no backup configuration.
            ObjectiveStatus::Undeclared => ProbeResult::warn(self::NAME, $this->kind(), $latencyMs, 'rpo_undeclared', $assessment->toDetail()),
            ObjectiveStatus::Indeterminate => ProbeResult::warn(self::NAME, $this->kind(), $latencyMs, 'rpo_indeterminate', $assessment->toDetail()),
            ObjectiveStatus::Breached, ObjectiveStatus::AtRisk => ProbeResult::fail(self::NAME, $this->kind(), $latencyMs, 'rpo_breached', $assessment->toDetail()),
        };
    }
}
