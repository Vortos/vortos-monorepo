<?php

declare(strict_types=1);

namespace Vortos\Deploy\Worker;

final readonly class DrainOutcome
{
    private function __construct(
        public WorkerHandle $worker,
        public bool $inFlightCompleted,
        public bool $forced,
        public int $durationMs,
        public int $attempts,
    ) {
        if ($inFlightCompleted && $forced) {
            throw new \LogicException('DrainOutcome cannot be both inFlightCompleted and forced.');
        }
    }

    public static function graceful(WorkerHandle $worker, int $durationMs, int $attempts = 1): self
    {
        return new self(
            worker: $worker,
            inFlightCompleted: true,
            forced: false,
            durationMs: $durationMs,
            attempts: $attempts,
        );
    }

    public static function forced(WorkerHandle $worker, int $durationMs, int $attempts = 1): self
    {
        return new self(
            worker: $worker,
            inFlightCompleted: false,
            forced: true,
            durationMs: $durationMs,
            attempts: $attempts,
        );
    }

    public static function noop(WorkerHandle $worker): self
    {
        return new self(
            worker: $worker,
            inFlightCompleted: false,
            forced: false,
            durationMs: 0,
            attempts: 0,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'worker' => $this->worker->toArray(),
            'in_flight_completed' => $this->inFlightCompleted,
            'forced' => $this->forced,
            'duration_ms' => $this->durationMs,
            'attempts' => $this->attempts,
        ];
    }
}
