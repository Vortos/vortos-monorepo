<?php

declare(strict_types=1);

namespace Vortos\Backup\Health;

use Vortos\Backup\PgBackRest\PgBackRestRepositoryInspector;
use Vortos\Backup\PgBackRest\PgBackRestRepositoryStatus;
use Vortos\Health\Probe\HealthProbeInterface;
use Vortos\Health\Probe\ProbeKind;
use Vortos\Health\Probe\ProbeResult;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * Fails when the pgBackRest repository cannot restore what it promises
 * ({@see PgBackRestRepositoryInspector}). MONITORING kind: a stale backup pages someone, it never
 * stops serving traffic. Delegated to the backup node, the only one that can reach the repository.
 */
#[AsDriver(self::NAME)]
final class PgBackRestRepositoryProbe implements HealthProbeInterface
{
    public const NAME = 'pgbackrest-repository';

    public function __construct(private readonly PgBackRestRepositoryInspector $inspector) {}

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
        $assessment = $this->inspector->assess();
        $latencyMs = round((microtime(true) - $start) * 1000, 2);

        return match ($assessment->status) {
            PgBackRestRepositoryStatus::Healthy => ProbeResult::pass(self::NAME, $this->kind(), $latencyMs, $assessment->toDetail()),
            // Warn, never skipped(): skipped is a Fail and would page an installation that does not use pgBackRest.
            PgBackRestRepositoryStatus::Undeclared => ProbeResult::warn(self::NAME, $this->kind(), $latencyMs, 'pgbackrest_undeclared', $assessment->toDetail()),
            PgBackRestRepositoryStatus::Failing => ProbeResult::fail(self::NAME, $this->kind(), $latencyMs, 'pgbackrest_repository_failing', $assessment->toDetail()),
        };
    }
}
