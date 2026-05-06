<?php

declare(strict_types=1);

namespace Vortos\Foundation\Health;

final class HealthResult
{
    public function __construct(
        public readonly string $name,
        public readonly bool   $healthy,
        public readonly float  $latencyMs,
        public readonly ?string $error = null,
        public readonly ?string $errorCode = null,
        public readonly bool $critical = true,
        public readonly bool $timedOut = false,
    ) {}

    public function toPublicArray(): array
    {
        return ['status' => $this->healthy ? 'ok' : 'degraded'];
    }

    public function toDetailedArray(bool $includeRawErrors = true): array
    {
        $data = [
            'status'     => $this->healthy ? 'ok' : 'degraded',
            'latency_ms' => $this->latencyMs,
            'critical'   => $this->critical,
        ];

        if ($this->error !== null) {
            $data['error_code'] = $this->errorCode ?? 'health_check_failed';

            if ($includeRawErrors) {
                $data['error'] = $this->error;
            }
        }

        if ($this->timedOut) {
            $data['timed_out'] = true;
        }

        return $data;
    }

    public function withRuntimeMetadata(bool $critical, int $timeoutMs): self
    {
        $timedOut = $this->latencyMs > $timeoutMs;

        return new self(
            $this->name,
            $this->healthy && !$timedOut,
            $this->latencyMs,
            $timedOut ? sprintf('Health check exceeded %dms timeout', $timeoutMs) : $this->error,
            $timedOut ? 'health_check_timeout' : $this->errorCode,
            $critical,
            $timedOut,
        );
    }
}
