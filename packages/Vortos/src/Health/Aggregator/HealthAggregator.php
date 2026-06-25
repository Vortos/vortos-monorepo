<?php

declare(strict_types=1);

namespace Vortos\Health\Aggregator;

use Vortos\Health\Probe\HealthProbeInterface;
use Vortos\Health\Probe\HealthProbeRegistry;
use Vortos\Health\Probe\ProbeKind;
use Vortos\Health\Probe\ProbeResult;
use Vortos\Health\Probe\ProbeStatus;
use Vortos\Health\Startup\StartupGate;

final class HealthAggregator
{
    private ?HealthReport $cachedReady = null;
    private ?float $cachedReadyAt = null;

    public function __construct(
        private readonly HealthProbeRegistry $registry,
        private readonly HealthBudget $budget,
        private readonly StartupGate $startupGate,
    ) {}

    public function live(): HealthReport
    {
        $probes = $this->registry->probesOfKind(ProbeKind::Liveness);

        if ($probes === []) {
            return $this->buildReport([], 'live');
        }

        return $this->runProbes($probes, 'live');
    }

    public function ready(): HealthReport
    {
        $cached = $this->getCachedReady();
        if ($cached !== null) {
            return $cached;
        }

        $probes = $this->registry->probesOfKind(ProbeKind::Readiness);
        $report = $this->runProbes($probes, 'ready');

        $this->setCachedReady($report);

        return $report;
    }

    public function startup(): HealthReport
    {
        if ($this->startupGate->isStarted()) {
            return $this->buildReport([], 'startup');
        }

        $probes = $this->registry->probesOfKind(ProbeKind::Startup);
        $report = $this->runProbes($probes, 'startup');

        if ($report->isHealthy()) {
            $this->startupGate->markStarted();
        }

        return $report;
    }

    /** @param list<HealthProbeInterface> $probes */
    private function runProbes(array $probes, string $mode): HealthReport
    {
        $results = [];
        $overallStart = $this->nowMs();

        foreach ($probes as $probe) {
            $elapsed = $this->nowMs() - $overallStart;

            if ($elapsed >= $this->budget->overallBudgetMs) {
                $results[] = ProbeResult::skipped($probe->name(), $probe->kind());
                continue;
            }

            $results[] = $this->runSingleProbe($probe);
        }

        return $this->buildReport($results, $mode);
    }

    private function runSingleProbe(HealthProbeInterface $probe): ProbeResult
    {
        $start = $this->nowMs();

        try {
            $result = $probe->check();
        } catch (\Throwable $e) {
            $elapsed = $this->nowMs() - $start;

            return ProbeResult::fail(
                $probe->name(),
                $probe->kind(),
                $elapsed,
                'probe_exception',
                ['exception' => $e::class],
            );
        }

        $elapsed = $this->nowMs() - $start;

        if ($elapsed > $this->budget->perProbeDeadlineMs) {
            return ProbeResult::timedOut($probe->name(), $probe->kind(), $elapsed, $this->budget->perProbeDeadlineMs);
        }

        return new ProbeResult(
            $result->status,
            $result->name,
            $result->kind,
            $elapsed,
            $result->detail,
            $result->errorCode,
            $result->timedOut,
            $result->skipped,
        );
    }

    /** @param list<ProbeResult> $results */
    private function buildReport(array $results, string $mode): HealthReport
    {
        $overall = ProbeStatus::Pass;
        $slowest = null;
        $totalLatency = 0.0;

        foreach ($results as $result) {
            $totalLatency += $result->latencyMs;

            if ($result->status === ProbeStatus::Fail) {
                $overall = ProbeStatus::Fail;
            } elseif ($result->status === ProbeStatus::Warn && $overall !== ProbeStatus::Fail) {
                $overall = ProbeStatus::Warn;
            }

            if ($slowest === null || $result->latencyMs > $slowest->latencyMs) {
                $slowest = $result;
            }
        }

        return new HealthReport(
            $overall,
            $mode,
            $results,
            $slowest,
            $totalLatency,
            new \DateTimeImmutable(),
        );
    }

    private function getCachedReady(): ?HealthReport
    {
        if ($this->cachedReady === null || $this->cachedReadyAt === null) {
            return null;
        }

        if ($this->budget->readyCacheTtlMs <= 0) {
            return null;
        }

        $age = $this->nowMs() - $this->cachedReadyAt;

        if ($age >= $this->budget->readyCacheTtlMs) {
            $this->cachedReady = null;
            $this->cachedReadyAt = null;

            return null;
        }

        return $this->cachedReady;
    }

    private function setCachedReady(HealthReport $report): void
    {
        if ($this->budget->readyCacheTtlMs > 0) {
            $this->cachedReady = $report;
            $this->cachedReadyAt = $this->nowMs();
        }
    }

    private function nowMs(): float
    {
        return hrtime(true) / 1_000_000;
    }
}
