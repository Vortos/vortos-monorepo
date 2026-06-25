<?php

declare(strict_types=1);

namespace Vortos\Health\Probe;

final readonly class ProbeResult
{
    /** @param array<string, scalar> $detail */
    public function __construct(
        public ProbeStatus $status,
        public string $name,
        public ProbeKind $kind,
        public float $latencyMs,
        public array $detail,
        public ?string $errorCode,
        public bool $timedOut,
        public bool $skipped,
    ) {}

    /** @param array<string, scalar> $detail */
    public static function pass(string $name, ProbeKind $kind, float $latencyMs, array $detail = []): self
    {
        return new self(ProbeStatus::Pass, $name, $kind, $latencyMs, $detail, null, false, false);
    }

    /** @param array<string, scalar> $detail */
    public static function warn(string $name, ProbeKind $kind, float $latencyMs, string $errorCode, array $detail = []): self
    {
        return new self(ProbeStatus::Warn, $name, $kind, $latencyMs, $detail, $errorCode, false, false);
    }

    /** @param array<string, scalar> $detail */
    public static function fail(string $name, ProbeKind $kind, float $latencyMs, string $errorCode, array $detail = []): self
    {
        return new self(ProbeStatus::Fail, $name, $kind, $latencyMs, $detail, $errorCode, false, false);
    }

    public static function timedOut(string $name, ProbeKind $kind, float $latencyMs, int $deadlineMs): self
    {
        return new self(
            ProbeStatus::Fail,
            $name,
            $kind,
            $latencyMs,
            ['deadline_ms' => $deadlineMs],
            'probe_timeout',
            true,
            false,
        );
    }

    public static function skipped(string $name, ProbeKind $kind): self
    {
        return new self(ProbeStatus::Fail, $name, $kind, 0.0, [], 'budget_exhausted', false, true);
    }

    public function isHealthy(): bool
    {
        return $this->status->isHealthy();
    }

    /** @return array{status: string} */
    public function toPublicArray(): array
    {
        return ['status' => $this->status->value];
    }

    /** @return array<string, mixed> */
    public function toDetailedArray(bool $includeErrors = true): array
    {
        $data = [
            'status' => $this->status->value,
            'kind' => $this->kind->value,
            'latency_ms' => round($this->latencyMs, 2),
        ];

        if ($this->timedOut) {
            $data['timed_out'] = true;
        }

        if ($this->skipped) {
            $data['skipped'] = true;
        }

        if ($this->errorCode !== null && $includeErrors) {
            $data['error_code'] = $this->errorCode;
        }

        if ($this->detail !== [] && $includeErrors) {
            $data['detail'] = $this->detail;
        }

        return $data;
    }
}
