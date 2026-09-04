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
    /**
     * How much cron to walk when measuring.
     *
     * Two weeks, not two days. Forty-eight hours covers every SUB-DAILY cadence, which was the only
     * kind declared when this was written — and a weekly schedule fires zero times inside it, so it
     * measured as unmeasurable and fell back to a 48-hour staleness threshold. For a backup that
     * runs every 168 hours that is not conservative, it is guaranteed: the artifact is older than
     * its own threshold for six days out of every seven, so the freshness alarm pages every week on
     * a perfectly healthy system.
     *
     * That is worse than not alerting. An alarm that cries wolf weekly is one people learn to
     * dismiss, and this is the alarm that exists to catch a backup worker that has silently died.
     *
     * Fourteen days makes weekly and fortnightly cadences measurable. Anything slower still falls
     * back, which is honest — but nothing slower than a fortnight is a backup cadence.
     */
    private const WINDOW_HOURS = 336;

    /**
     * Hard bound on the walk so a dense-but-valid expression cannot spin. Scaled with the window:
     * the walk stops at whichever comes first, and a minutely cron would otherwise be cut short and
     * measured against a truncated series.
     */
    private const MAX_STEPS = 1344;

    /**
     * Memo of cron => interval, safe precisely because this class is pure.
     *
     * Measuring walks the expression against a FIXED reference instant, so the answer for a given
     * cron never changes — not between calls, not between requests, not across a worker's lifetime.
     * That is what makes caching it different from the usual trap of memoising in a service
     * property under a long-lived worker: there is no request-scoped input to go stale.
     *
     * Worth having because widening the window made a measurement cost a few hundred milliseconds,
     * and freshness inspects every declared schedule on every run.
     *
     * @var array<string, float|null>
     */
    private array $measured = [];

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
        if (\array_key_exists($cron, $this->measured)) {
            return $this->measured[$cron];
        }

        return $this->measured[$cron] = $this->measure($cron);
    }

    private function measure(string $cron): ?float
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
