<?php

declare(strict_types=1);

namespace Vortos\Deploy\Worker;

use Vortos\Docker\Worker\WorkerProcessRegistry;

final readonly class WorkerRolloutPlan
{
    /** @param list<WorkerHandle> $handles */
    public function __construct(
        public array $handles,
    ) {}

    public static function fromRegistry(WorkerProcessRegistry $registry): self
    {
        $handles = [];
        foreach ($registry->all() as $def) {
            $handles[] = new WorkerHandle(
                programName: $def->supervisorProgramName(),
                numprocs: $def->numprocs,
                drainDeadline: $def->drainDeadline,
            );
        }

        return new self($handles);
    }

    public function isEmpty(): bool
    {
        return $this->handles === [];
    }

    public function count(): int
    {
        return \count($this->handles);
    }

    /** @return array<int, array<string, mixed>> */
    public function toArray(): array
    {
        return array_map(
            static fn (WorkerHandle $h) => $h->toArray(),
            $this->handles,
        );
    }
}
