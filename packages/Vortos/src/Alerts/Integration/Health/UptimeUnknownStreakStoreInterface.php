<?php

declare(strict_types=1);

namespace Vortos\Alerts\Integration\Health;

/**
 * Persists consecutive-`Unknown` tick counts per monitor — the "blind detector"
 * meta-alert (§5.4) needs state across CLI-invoked ticks, which don't share process
 * memory. A non-`Unknown` status resets the streak for that monitor.
 */
interface UptimeUnknownStreakStoreInterface
{
    /** Increments and returns the new streak count for this monitor. */
    public function increment(string $monitorId): int;

    public function reset(string $monitorId): void;
}
