<?php

declare(strict_types=1);

namespace Vortos\Backup\Health;

use Psr\Clock\ClockInterface;
use Vortos\Backup\Catalog\BackupCatalogReadModelInterface;
use Vortos\Backup\Schedule\BackupScheduleRegistry;
use Vortos\Backup\Schedule\BackupScheduleType;
use Vortos\Backup\Schedule\CadenceInterval;

/**
 * "When did a backup last actually succeed?" — the question the dead-man alert could not ask.
 *
 * THE POINT, and the reason this reads from the catalog and nothing else: the pre-existing
 * `backup.failed` alert fires from inside {@see \Vortos\Backup\Runtime\BackupWorker}'s catch block, so
 * it can only report a run that was *attempted and threw*. A worker that never attempts a run emits
 * nothing at all — and in production (2026-07-07 → 07-22) that is exactly what happened: a wedged
 * worker sat healthy and silent for 15 days while the alerting it was wired to had, correctly and by
 * construction, nothing to say.
 *
 * So this inspector deliberately shares NO state with the worker. It reads the catalog — the durable
 * record of backups that genuinely completed — which stays true when the worker is wedged, stopped,
 * crash-looping, misconfigured, or its container deleted outright. If the answer is "no row since
 * Tuesday", that is the truth regardless of what any process believes about itself.
 *
 * Thresholds derive from the declared cadence via {@see CadenceInterval}, so a config that tightens
 * its backup schedule tightens its own alerting with no second knob to forget. `toleranceFactor`
 * absorbs ordinary jitter (a slow dump, a missed tick); the default of 2.5 means a 6-hourly cadence
 * alerts at 15h — late enough not to page on one skipped run, early enough to be actionable.
 *
 * Pure with respect to time: {@see inspect()} takes the clock, and every threshold is computed rather
 * than remembered, so this is fully testable without touching a database.
 */
final class BackupFreshnessInspector
{
    /**
     * Multiplier applied to the declared cadence to get the staleness threshold. One missed run is
     * noise; two consecutive misses is a pattern worth waking someone for.
     */
    public const DEFAULT_TOLERANCE_FACTOR = 2.5;

    /** Floor for the derived threshold — never alert more eagerly than this, whatever the cadence. */
    private const MIN_THRESHOLD_SECONDS = 900;

    public function __construct(
        private readonly BackupCatalogReadModelInterface $catalog,
        private readonly BackupScheduleRegistry $schedules,
        private readonly ClockInterface $clock,
        private readonly CadenceInterval $cadence = new CadenceInterval(),
        private readonly float $toleranceFactor = self::DEFAULT_TOLERANCE_FACTOR,
        /**
         * Fallback threshold for a cadence too slow to measure (fires less than twice in 48h). Such a
         * schedule cannot derive its own threshold, and defaulting to something tight would alert
         * constantly, so it gets an explicit, conservative ceiling instead.
         */
        private readonly int $unmeasurableCadenceThresholdSeconds = 172800,
    ) {
    }

    /**
     * Inspect every declared backup target. One verdict per backup-type schedule; retention and drill
     * schedules are not backups and are intentionally out of scope here.
     *
     * @return list<BackupFreshness>
     */
    public function inspect(): array
    {
        $now = $this->clock->now();
        $results = [];

        foreach ($this->schedules->all() as $schedule) {
            if ($schedule->type !== BackupScheduleType::Backup) {
                continue;
            }

            $maxAge = $this->thresholdFor($schedule->cron);
            $latest = $this->catalog->latest($schedule->engine, $schedule->environment);

            if ($latest === null) {
                $results[] = new BackupFreshness(
                    engine: $schedule->engine,
                    environment: $schedule->environment,
                    status: BackupFreshnessStatus::NeverRun,
                    lastSuccessAt: null,
                    ageSeconds: null,
                    maxAgeSeconds: $maxAge,
                );

                continue;
            }

            $ageSeconds = max(0, $now->getTimestamp() - $latest->createdAt->getTimestamp());

            $results[] = new BackupFreshness(
                engine: $schedule->engine,
                environment: $schedule->environment,
                status: $ageSeconds > $maxAge ? BackupFreshnessStatus::Stale : BackupFreshnessStatus::Fresh,
                lastSuccessAt: $latest->createdAt,
                ageSeconds: $ageSeconds,
                maxAgeSeconds: $maxAge,
                lastBackupId: $latest->id->value(),
            );
        }

        return $results;
    }

    /** @return list<BackupFreshness> only the targets that are not healthy. */
    public function breaches(): array
    {
        return array_values(array_filter($this->inspect(), static fn (BackupFreshness $f): bool => !$f->isHealthy()));
    }

    /** The staleness threshold, in seconds, derived from a backup schedule's cron. */
    public function thresholdFor(string $cron): int
    {
        $interval = $this->cadence->shortestIntervalSeconds($cron);

        if ($interval === null) {
            return $this->unmeasurableCadenceThresholdSeconds;
        }

        return max(self::MIN_THRESHOLD_SECONDS, (int) round($interval * $this->toleranceFactor));
    }
}
