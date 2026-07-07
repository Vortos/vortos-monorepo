<?php

declare(strict_types=1);

namespace Vortos\Backup\Schedule;

use InvalidArgumentException;

/**
 * The set of declared backup schedules. The app registers its schedules (e.g. a
 * nightly logical dump + a weekly base backup) and {@see CronFragmentGenerator}
 * renders them into a host fragment.
 *
 * @implements \IteratorAggregate<int, BackupSchedule>
 */
final class BackupScheduleRegistry implements \IteratorAggregate
{
    /** @var array<string, BackupSchedule> */
    private array $schedules = [];

    /** @param iterable<BackupSchedule> $schedules */
    public function __construct(iterable $schedules = [])
    {
        foreach ($schedules as $schedule) {
            $this->add($schedule);
        }
    }

    public function add(BackupSchedule $schedule): void
    {
        if (isset($this->schedules[$schedule->name])) {
            throw new InvalidArgumentException("Duplicate backup schedule name '{$schedule->name}'.");
        }
        $this->schedules[$schedule->name] = $schedule;
    }

    /** @return list<BackupSchedule> */
    public function all(): array
    {
        $all = array_values($this->schedules);
        usort($all, static fn (BackupSchedule $a, BackupSchedule $b): int => $a->name <=> $b->name);

        return $all;
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->all());
    }
}
