<?php

declare(strict_types=1);

namespace Vortos\Deploy\State;

final class DeployRun
{
    /** @var array<int, StepOutcome> Keyed by step index */
    private array $outcomes = [];

    public function __construct(
        public readonly string $runId,
        public readonly string $env,
        public readonly string $planHash,
        public readonly string $definitionHash,
        public readonly string $desiredDigest,
        public readonly string $desiredRepository = '',
        public DeployStatus $status = DeployStatus::Pending,
        public readonly \DateTimeImmutable $startedAt = new \DateTimeImmutable(),
        public \DateTimeImmutable $updatedAt = new \DateTimeImmutable(),
    ) {}

    public function isStepCompleted(int $stepIndex): bool
    {
        return isset($this->outcomes[$stepIndex])
            && $this->outcomes[$stepIndex]->status === StepStatus::Success;
    }

    public function addOutcome(StepOutcome $outcome): void
    {
        $this->outcomes[$outcome->stepIndex] = $outcome;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** @return array<int, StepOutcome> */
    public function outcomes(): array
    {
        return $this->outcomes;
    }

    public function completedStepCount(): int
    {
        $count = 0;
        foreach ($this->outcomes as $outcome) {
            if ($outcome->status === StepStatus::Success) {
                $count++;
            }
        }

        return $count;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'env' => $this->env,
            'plan_hash' => $this->planHash,
            'definition_hash' => $this->definitionHash,
            'desired_digest' => $this->desiredDigest,
            'desired_repository' => $this->desiredRepository,
            'status' => $this->status->value,
            'outcomes' => array_map(
                static fn (StepOutcome $o): array => $o->toArray(),
                array_values($this->outcomes),
            ),
            'started_at' => $this->startedAt->format(\DateTimeInterface::ATOM),
            'updated_at' => $this->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $run = new self(
            runId: (string) $data['run_id'],
            env: (string) $data['env'],
            planHash: (string) $data['plan_hash'],
            definitionHash: (string) $data['definition_hash'],
            desiredDigest: (string) $data['desired_digest'],
            desiredRepository: (string) ($data['desired_repository'] ?? ''),
            status: DeployStatus::from((string) $data['status']),
            startedAt: new \DateTimeImmutable((string) $data['started_at']),
            updatedAt: new \DateTimeImmutable((string) $data['updated_at']),
        );

        foreach ($data['outcomes'] ?? [] as $outcomeData) {
            $run->addOutcome(StepOutcome::fromArray($outcomeData));
        }

        return $run;
    }
}
