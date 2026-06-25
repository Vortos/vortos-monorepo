<?php

declare(strict_types=1);

namespace Vortos\Deploy\Gate;

final readonly class SmokeCheckResult
{
    public function __construct(
        public string $path,
        public bool $passed,
        public int $statusCode,
        public float $latency,
        public string $reason = '',
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'passed' => $this->passed,
            'status_code' => $this->statusCode,
            'latency' => $this->latency,
            'reason' => $this->reason,
        ];
    }
}
