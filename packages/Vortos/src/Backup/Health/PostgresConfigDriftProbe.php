<?php

declare(strict_types=1);

namespace Vortos\Backup\Health;

use Vortos\Backup\DR\PostgresConfigDriftInspector;
use Vortos\Backup\DR\PostgresConfigDriftStatus;
use Vortos\Health\Probe\HealthProbeInterface;
use Vortos\Health\Probe\ProbeKind;
use Vortos\Health\Probe\ProbeResult;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * Fails when the running PostgreSQL cluster is configured by anything but the declared file (RC-5).
 *
 * MONITORING kind: a hand-applied ALTER SYSTEM means page someone, never stop serving traffic — the
 * database is still answering, it is just no longer the database the repository describes.
 */
#[AsDriver(self::NAME)]
final class PostgresConfigDriftProbe implements HealthProbeInterface
{
    public const NAME = 'postgres-config-drift';

    public function __construct(private readonly PostgresConfigDriftInspector $inspector) {}

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
            PostgresConfigDriftStatus::Clean => ProbeResult::pass(self::NAME, $this->kind(), $latencyMs, $report->toDetail()),
            // Warn, never ProbeResult::skipped() (a Fail): an installation that declares no config file is not drifting.
            PostgresConfigDriftStatus::Undeclared => ProbeResult::warn(self::NAME, $this->kind(), $latencyMs, 'config_undeclared', $report->toDetail()),
            PostgresConfigDriftStatus::Indeterminate => ProbeResult::warn(self::NAME, $this->kind(), $latencyMs, 'config_indeterminate', $report->toDetail()),
            // Warn: this node's role does not watch the cluster (a least-privilege application role), so the answer
            // belongs to the node that does. Failing here would page for every colour of a perfectly configured
            // database — which is exactly what it did the first night the application stopped being a superuser.
            PostgresConfigDriftStatus::NotWatchedHere => ProbeResult::warn(self::NAME, $this->kind(), $latencyMs, 'config_not_watched_here', $report->toDetail()),
            PostgresConfigDriftStatus::Drifted => ProbeResult::fail(self::NAME, $this->kind(), $latencyMs, 'config_drifted', $report->toDetail()),
            PostgresConfigDriftStatus::Unverifiable => ProbeResult::fail(self::NAME, $this->kind(), $latencyMs, 'config_unverifiable', $report->toDetail()),
        };
    }
}
