<?php

declare(strict_types=1);

namespace Vortos\Migration\Health;

use Vortos\Health\Probe\HealthProbeInterface;
use Vortos\Health\Probe\ProbeKind;
use Vortos\Health\Probe\ProbeResult;
use Vortos\Migration\Access\DatabaseRoleConformanceInspector;
use Vortos\Migration\Access\DatabaseRoleConformanceStatus;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * Fails when the live database departs from the declared least-privilege role model.
 *
 * MONITORING kind: a role granted SUPERUSER by hand, or a table left readable by PUBLIC, means page someone — the
 * application still works, it has just lost the boundary that keeps one tenant's session out of another's rows.
 */
#[AsDriver(self::NAME)]
final class DatabaseRoleConformanceProbe implements HealthProbeInterface
{
    public const NAME = 'database-role-conformance';

    public function __construct(private readonly DatabaseRoleConformanceInspector $inspector) {}

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
        $report = $this->inspector->inspect();
        $latencyMs = round((microtime(true) - $start) * 1000, 2);

        return match ($report->status) {
            DatabaseRoleConformanceStatus::Conforming => ProbeResult::pass(self::NAME, $this->kind(), $latencyMs, $report->toProbeDetail()),
            // Warn, never ProbeResult::skipped() (a Fail): an installation that declares no role model is not violating one.
            DatabaseRoleConformanceStatus::Undeclared => ProbeResult::warn(self::NAME, $this->kind(), $latencyMs, 'roles_undeclared', $report->toProbeDetail()),
            DatabaseRoleConformanceStatus::Indeterminate => ProbeResult::warn(self::NAME, $this->kind(), $latencyMs, 'roles_indeterminate', $report->toProbeDetail()),
            DatabaseRoleConformanceStatus::Violated => ProbeResult::fail(self::NAME, $this->kind(), $latencyMs, 'roles_violated', $report->toProbeDetail()),
        };
    }
}
