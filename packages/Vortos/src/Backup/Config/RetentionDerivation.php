<?php

declare(strict_types=1);

namespace Vortos\Backup\Config;

use Vortos\Backup\Schedule\CadenceInterval;

/**
 * R8-6 (A7): derive a sensible `hourly` retention bucket from a declared backup cadence, so a
 * sub-daily schedule (e.g. every 6h) does not silently collapse to one kept backup per day the
 * moment retention runs.
 *
 * The rule: measure the shortest gap between consecutive fires of the backup cron; if it is sub-daily,
 * keep roughly two days' worth of those restore points (`ceil(48h / intervalHours)`), clamped to a
 * sane ceiling. A daily-or-slower cadence needs no hourly bucket (the daily/weekly buckets cover it),
 * so derivation returns 0 and the caller leaves `hourly` at its default.
 *
 * Pure: given a cron string it always returns the same number.
 */
final class RetentionDerivation
{
    /** Never keep more than this many hourly restore points, however dense the cadence. */
    public const MAX_HOURLY = 48;

    private const WINDOW_HOURS = 48;

    public function __construct(
        private readonly CadenceInterval $cadence = new CadenceInterval(),
    ) {
    }

    /**
     * @return int the derived hourly bucket count, or 0 when the cadence is daily-or-slower (no hourly
     *             bucket needed).
     */
    public function hourlyFor(string $cron): int
    {
        $intervalHours = $this->cadence->shortestIntervalHours($cron);

        if ($intervalHours === null || $intervalHours >= 24.0) {
            return 0;
        }

        $hourly = (int) ceil(self::WINDOW_HOURS / $intervalHours);

        return max(1, min(self::MAX_HOURLY, $hourly));
    }
}
