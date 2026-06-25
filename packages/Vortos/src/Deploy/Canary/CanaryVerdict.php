<?php

declare(strict_types=1);

namespace Vortos\Deploy\Canary;

final readonly class CanaryVerdict
{
    /** @param list<MetricEvaluation> $evaluations */
    public function __construct(
        public CanaryDecision $decision,
        public array $evaluations,
        public string $reason,
        public int $totalSamples,
        public \DateTimeImmutable $at,
    ) {}

    public function isRollback(): bool
    {
        return $this->decision === CanaryDecision::Rollback;
    }

    public function isProgress(): bool
    {
        return $this->decision === CanaryDecision::Progress;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'decision' => $this->decision->name,
            'reason' => $this->reason,
            'total_samples' => $this->totalSamples,
            'at' => $this->at->format(\DateTimeInterface::ATOM),
            'evaluations' => array_map(static fn (MetricEvaluation $e): array => [
                'slo' => $e->sloName,
                'comparator' => $e->comparator->name,
                'staged_value' => $e->stagedValue,
                'stable_value' => $e->stableValue,
                'breached' => $e->breached,
                'reason' => $e->reason,
            ], $this->evaluations),
        ];
    }
}
