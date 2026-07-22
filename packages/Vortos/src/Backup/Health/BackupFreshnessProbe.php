<?php

declare(strict_types=1);

namespace Vortos\Backup\Health;

use Throwable;
use Vortos\Health\Probe\HealthProbeInterface;
use Vortos\Health\Probe\ProbeKind;
use Vortos\Health\Probe\ProbeResult;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * Surfaces {@see BackupFreshnessInspector}'s verdict as a health probe, so "our backups stopped"
 * becomes visible to whatever already watches the application from **outside the box**.
 *
 * This is the piece that closes the 2026-07 incident properly. The backup worker went silent for 15
 * days, and every mechanism that could have noticed lived on the same host as the failure: the
 * dead-man alert fires from inside the worker, and the worker was the thing that had stopped. An
 * external uptime monitor already polls this application's health endpoints from off-host; putting
 * freshness on that surface means the alert path no longer depends on the health of the machine it is
 * reporting about.
 *
 * MONITORING kind, deliberately and non-negotiably. A stale backup means "page someone", never "stop
 * serving traffic": stale backups say nothing about whether this process can handle a request. A
 * readiness-kind probe here would fail the blue-green health gate on every deploy that follows a
 * backup hiccup, auto-roll-back a perfectly good release, and — worst — drop the color out of the
 * edge's upstream pool, converting a backup problem into a customer-facing outage. Monitoring-kind
 * probes are sampled by the monitor tick and reported at /health/monitor, and are never aggregated
 * into /health/live|ready|startup.
 *
 * Reports the single worst target: with several environments in one catalog, one stale target must
 * not be averaged away by healthy neighbours.
 */
final class BackupFreshnessProbe implements HealthProbeInterface
{
    public function __construct(
        private readonly BackupFreshnessInspector $inspector,
    ) {
    }

    public function name(): string
    {
        return 'backup-freshness';
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

        try {
            $results = $this->inspector->inspect();
        } catch (Throwable $e) {
            // An unreachable catalog is itself worth surfacing, but it is not evidence that backups
            // are stale — report it as its own distinct condition rather than a false alarm.
            return ProbeResult::warn(
                $this->name(),
                $this->kind(),
                $this->elapsedMs($start),
                'backup_freshness_indeterminate',
                ['error' => $e->getMessage()],
            );
        }

        $latencyMs = $this->elapsedMs($start);

        if ($results === []) {
            return ProbeResult::pass($this->name(), $this->kind(), $latencyMs, ['targets' => 0]);
        }

        $worst = $this->worst($results);

        if ($worst->isHealthy()) {
            return ProbeResult::pass($this->name(), $this->kind(), $latencyMs, $worst->toDetail());
        }

        return ProbeResult::fail(
            $this->name(),
            $this->kind(),
            $latencyMs,
            $worst->status === BackupFreshnessStatus::NeverRun ? 'backup_never_run' : 'backup_stale',
            $worst->toDetail() + ['summary' => $worst->describe()],
        );
    }

    /**
     * @param non-empty-list<BackupFreshness> $results
     */
    private function worst(array $results): BackupFreshness
    {
        $rank = static fn (BackupFreshness $f): int => match ($f->status) {
            BackupFreshnessStatus::NeverRun => 2,
            BackupFreshnessStatus::Stale => 1,
            BackupFreshnessStatus::Fresh => 0,
        };

        $worst = $results[0];
        foreach ($results as $candidate) {
            if ($rank($candidate) > $rank($worst)) {
                $worst = $candidate;
            }
        }

        return $worst;
    }

    private function elapsedMs(float $start): float
    {
        return (microtime(true) - $start) * 1000.0;
    }
}
