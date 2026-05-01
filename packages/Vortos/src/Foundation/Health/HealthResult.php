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
    ) {}

    public function toPublicArray(): array
    {
        return ['status' => $this->healthy ? 'ok' : 'degraded'];
    }

    public function toDetailedArray(): array
    {
        $data = [
            'status'     => $this->healthy ? 'ok' : 'degraded',
            'latency_ms' => $this->latencyMs,
        ];

        if ($this->error !== null) {
            $data['error'] = $this->error;
        }

        return $data;
    }
}
