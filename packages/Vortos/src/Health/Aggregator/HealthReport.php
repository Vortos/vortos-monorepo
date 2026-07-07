<?php

declare(strict_types=1);

namespace Vortos\Health\Aggregator;

use Vortos\Health\Probe\ProbeResult;
use Vortos\Health\Probe\ProbeStatus;

final readonly class HealthReport
{
    /**
     * @param list<ProbeResult> $results
     */
    public function __construct(
        public ProbeStatus $overallStatus,
        public string $mode,
        public array $results,
        public ?ProbeResult $slowest,
        public float $totalLatencyMs,
        public \DateTimeImmutable $timestamp,
    ) {}

    public function isHealthy(): bool
    {
        return $this->overallStatus->isHealthy();
    }

    /** The mode name of an informational monitoring report (GAP-F); never a readiness gate. */
    public const MONITOR_MODE = 'monitor';

    public function httpStatusCode(): int
    {
        // GAP-F: the monitoring report is informational — it carries per-probe status in its body but
        // must always return 200 so no orchestrator/edge mistakes a cert-near-expiry (or any monitoring
        // breach) for unreadiness. Gate modes (live/ready/startup) keep 503-on-unhealthy.
        if ($this->mode === self::MONITOR_MODE) {
            return 200;
        }

        return $this->isHealthy() ? 200 : 503;
    }

    /**
     * @return array{status: string, mode: string, timestamp: string, checks: array<string, array<string, mixed>>, total_latency_ms: float, slowest?: string}
     */
    public function toDetailedArray(bool $includeErrors = true): array
    {
        $checks = [];
        foreach ($this->results as $result) {
            $checks[$result->name] = $result->toDetailedArray($includeErrors);
        }

        $data = [
            'status' => $this->overallStatus->value,
            'mode' => $this->mode,
            'timestamp' => $this->timestamp->format(\DateTimeInterface::ATOM),
            'checks' => $checks,
            'total_latency_ms' => round($this->totalLatencyMs, 2),
        ];

        if ($this->slowest !== null) {
            $data['slowest'] = $this->slowest->name;
        }

        return $data;
    }

    /**
     * @param bool $includeDegradedNames R8-5: when the report is not passing, list the NAMES of the
     *   non-passing checks. Names are capability identifiers (e.g. "disk-capacity"), never messages,
     *   values, DSNs, or errors — so an operator can diagnose a warn/fail over HTTP without shelling
     *   in, and an anonymous caller still learns nothing sensitive. Suppressible for the most paranoid
     *   deployments (VORTOS_HEALTH_PUBLIC_DEGRADED_NAMES=false).
     *
     * @return array{status: string, mode: string, timestamp: string, degraded?: list<string>}
     */
    public function toPublicArray(bool $includeDegradedNames = true): array
    {
        $data = [
            'status' => $this->overallStatus->value,
            'mode' => $this->mode,
            'timestamp' => $this->timestamp->format(\DateTimeInterface::ATOM),
        ];

        if ($includeDegradedNames) {
            $degraded = $this->degradedCheckNames();
            if ($degraded !== []) {
                $data['degraded'] = $degraded;
            }
        }

        return $data;
    }

    /**
     * Names of checks that are not passing (warn or fail), skipped checks excluded. Sorted, unique.
     *
     * @return list<string>
     */
    public function degradedCheckNames(): array
    {
        $names = [];
        foreach ($this->results as $result) {
            if (!$result->skipped && $result->status !== ProbeStatus::Pass) {
                $names[$result->name] = true;
            }
        }

        $names = array_keys($names);
        sort($names);

        return $names;
    }
}
