<?php

declare(strict_types=1);

namespace Vortos\Backup\Runtime;

final class InMemoryScheduleStateStore implements ScheduleStateStoreInterface
{
    /** @var array<string, ScheduleState> */
    private array $states = [];

    public function get(string $scheduleName): ScheduleState
    {
        return $this->states[$scheduleName] ?? new ScheduleState();
    }

    public function put(string $scheduleName, ScheduleState $state): void
    {
        $this->states[$scheduleName] = $state;
    }
}
