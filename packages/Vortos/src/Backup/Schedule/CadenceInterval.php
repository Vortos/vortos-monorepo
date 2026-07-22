<?php

declare(strict_types=1);

namespace Vortos\Backup\Schedule;

use DateTimeImmutable;
use DateTimeZone;
use Vortos\Backup\Runtime\CronDueEvaluator;

/**
 * "How often does this cron actually fire?" — the one place that answers it.
 *
 * Two independent consumers need the same number and must not disagree about it: retention derives
 * its hourly bucket from the backup cadence ({@see \Vortos\Backup\Config\RetentionDerivation}), and
 * freshness derives its staleness threshold from the same cadence
 * ({@see \Vortos\Backup\Health\BackupFreshnessInspector}). If those two ever drifted, retention could
 * prune restore points that freshness still considers current — so the measurement lives here once.
 *
 * Pure: given a cron string it always returns the same number, measured against a fixed reference
 * instant rather than "now".
 */
final class CadenceInterval
{
    /** How much cron to walk when measuring. Two days covers every sub-daily cadence. */
    private const WINDOW_HOURS = 48;

    /** Hard bound on the walk so a dense-but-valid expression cannot spin. */
    private const MAX_STEPS = 192;

    public function __construct(
        private readonly CronDueEvaluator $evaluator = new CronDueEvaluator(),
    ) {
    }

    /**
     * The shortest gap between consecutive fires, in seconds, or null when fewer than two fires occur
     * within the measurement window (i.e. the cadence is slower than 48h and cannot be measured this
     * way — callers must supply their own expectation).
     */
    public function shortestIntervalSeconds(string $cron): ?int
    {
        $hours = $this->shortestIntervalHours($cron);

        return $hours === null ? null : (int) round($hours * 3600);
    }

    /** As {@see shortestIntervalSeconds()}, in fractional hours. */
    public function shortestIntervalHours(string $cron): ?float
    {
        // A fixed, deterministic reference — a plain UTC week start — so derivation is pure.
        $cursor = new DateTimeImmutable('2024-01-01 00:00:00', new DateTimeZone('UTC'));
        $end = $cursor->modify('+' . self::WINDOW_HOURS . ' hours');

        $previous = null;
        $shortest = null;

        for ($i = 0; $i < self::MAX_STEPS; $i++) {
            $next = $this->evaluator->nextDueAfter($cron, $cursor);
            if ($next > $end) {
                break;
            }

            if ($previous !== null) {
                $gapHours = ($next->getTimestamp() - $previous->getTimestamp()) / 3600.0;
                $shortest = $shortest === null ? $gapHours : min($shortest, $gapHours);
            }

            $previous = $next;
            $cursor = $next;
        }

        return $shortest;
    }
}
