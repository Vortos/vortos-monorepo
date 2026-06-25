<?php

declare(strict_types=1);

namespace Vortos\Deploy\Worker;

final readonly class WorkerHandle
{
    public function __construct(
        public string $programName,
        public int $numprocs,
        public int $drainDeadline,
    ) {
        if ($programName === '') {
            throw new \InvalidArgumentException('WorkerHandle programName cannot be empty.');
        }

        if ($numprocs < 1) {
            throw new \InvalidArgumentException('WorkerHandle numprocs must be at least 1.');
        }

        if ($drainDeadline < 1) {
            throw new \InvalidArgumentException('WorkerHandle drainDeadline must be at least 1.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'program_name' => $this->programName,
            'numprocs' => $this->numprocs,
            'drain_deadline' => $this->drainDeadline,
        ];
    }
}
