<?php

declare(strict_types=1);

namespace Vortos\Backup\Runtime;

/**
 * Durable per-schedule watermark + failure state for the backup worker. A restart must not lose the
 * last-fired watermark (else the worker could double-fire or miss a window), so production wiring uses
 * a filesystem/DB-backed implementation; tests use the in-memory one.
 */
interface ScheduleStateStoreInterface
{
    public function get(string $scheduleName): ScheduleState;

    public function put(string $scheduleName, ScheduleState $state): void;
}
