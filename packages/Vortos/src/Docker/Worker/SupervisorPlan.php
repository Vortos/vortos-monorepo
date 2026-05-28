<?php

declare(strict_types=1);

namespace Vortos\Docker\Worker;

final readonly class SupervisorPlan
{
    public function __construct(
        public string $path,
        public SupervisorChange $change,
        public string $current,
        public string $desired,
    ) {}

    public function hasChanges(): bool
    {
        return $this->change !== SupervisorChange::None;
    }
}
