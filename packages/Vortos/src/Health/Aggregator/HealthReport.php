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
     * @return array{status: string, mode: string, timestamp: string}
     */
    public function toPublicArray(): array
    {
        return [
            'status' => $this->overallStatus->value,
            'mode' => $this->mode,
            'timestamp' => $this->timestamp->format(\DateTimeInterface::ATOM),
        ];
    }
}
