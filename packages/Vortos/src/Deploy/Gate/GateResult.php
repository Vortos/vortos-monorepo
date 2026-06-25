<?php

declare(strict_types=1);

namespace Vortos\Deploy\Gate;

final readonly class GateResult
{
    public function __construct(
        public bool $passed,
        public int $attempts,
        public float $elapsed,
        public ?int $lastStatusCode = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'passed' => $this->passed,
            'attempts' => $this->attempts,
            'elapsed' => $this->elapsed,
            'last_status_code' => $this->lastStatusCode,
        ];
    }
}
