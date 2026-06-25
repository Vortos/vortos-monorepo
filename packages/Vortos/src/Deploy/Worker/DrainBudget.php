<?php

declare(strict_types=1);

namespace Vortos\Deploy\Worker;

final readonly class DrainBudget
{
    public function __construct(
        public int $deadlineSeconds,
        public int $pollIntervalMs = 500,
    ) {
        if ($deadlineSeconds < 1 || $deadlineSeconds > 3600) {
            throw new \InvalidArgumentException(sprintf(
                'DrainBudget deadlineSeconds must be 1..3600, got %d.',
                $deadlineSeconds,
            ));
        }

        if ($pollIntervalMs < 50 || $pollIntervalMs > 5000) {
            throw new \InvalidArgumentException(sprintf(
                'DrainBudget pollIntervalMs must be 50..5000, got %d.',
                $pollIntervalMs,
            ));
        }
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'deadline_seconds' => $this->deadlineSeconds,
            'poll_interval_ms' => $this->pollIntervalMs,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            deadlineSeconds: (int) ($data['deadline_seconds'] ?? 0),
            pollIntervalMs: (int) ($data['poll_interval_ms'] ?? 500),
        );
    }
}
