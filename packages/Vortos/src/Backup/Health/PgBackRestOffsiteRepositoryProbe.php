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
 * Fails when the off-site pgBackRest copy could not save the day it exists for: it is stale, behind the
 * archive, unreadable — or its bucket no longer carries a COMPLIANCE lock as long as declared
 * ({@see PgBackRestRepositoryInspector} for the off-site role). A separate probe from the primary's so
 * each pages under its own name and neither can mask the other. MONITORING kind, delegated to the
 * backup node; Warn where no off-site copy is declared.
 */
#[AsDriver(self::NAME)]
final class PgBackRestOffsiteRepositoryProbe implements HealthProbeInterface
{
    public const NAME = 'pgbackrest-offsite-repository';

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
            PgBackRestRepositoryStatus::Undeclared => ProbeResult::warn(self::NAME, $this->kind(), $latencyMs, 'pgbackrest_offsite_undeclared', $assessment->toDetail()),
            PgBackRestRepositoryStatus::Failing => ProbeResult::fail(self::NAME, $this->kind(), $latencyMs, 'pgbackrest_offsite_failing', $assessment->toDetail()),
        };
    }
}
