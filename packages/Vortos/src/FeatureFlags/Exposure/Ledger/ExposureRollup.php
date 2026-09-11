<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Exposure\Ledger;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Folds the raw ledger into the daily rollup, and prunes both to their retention windows.
 *
 * ## Why a rollup exists at all
 *
 * The raw ledger answers every question the rollup does, so long as it still holds the days
 * being asked about — and it cannot hold them for long, because it is the highest-volume
 * table in the schema. The rollup is how a two-year comparison survives a thirty-day table:
 * it is two orders of magnitude smaller (subjects collapse into a count) and therefore cheap
 * to keep for years.
 *
 * ## Idempotent, and re-runnable over history
 *
 * Every rollup is an upsert keyed on the same grain as the target table, so running it twice
 * over the same day produces the same row. That is what makes it safe to schedule naively
 * (no "did yesterday's run happen?" bookkeeping) and safe to re-run by hand after a bug.
 *
 * ## The ordering constraint that makes this safe
 *
 * Pruning the ledger destroys the only source the rollup can be rebuilt from. So
 * {@see self::prune()} refuses to delete a day that has not been rolled up — not by checking
 * a flag, but by rolling it up first. An operator who shortens retention, or a scheduler that
 * misses a night, therefore cannot silently turn a gap in the rollup into a permanent one.
 */
final readonly class ExposureRollup
{
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
        private string $ledgerTable,
        private string $rollupTable,
    ) {}

    /**
     * Roll up a single UTC day.
     *
     * `count(*)` is a distinct-subject count without needing `DISTINCT`, because the ledger's
     * primary key already guarantees one row per subject per flag per variant per day. That
     * identity is the reason the grain was chosen; if the primary key ever widened, this
     * count would quietly start meaning something else.
     *
     * @return int rollup rows written for that day
     */
    public function rollupDay(string $day): int
    {
        return (int) $this->connection->executeStatement(
            'INSERT INTO ' . $this->rollupTable . '
                 (day, flag, variant, source, group_key, subjects, rolled_up_at)
             SELECT day, flag, variant, source, group_key, count(*), :now
             FROM ' . $this->ledgerTable . '
             WHERE day = :day
             GROUP BY day, flag, variant, source, group_key
             ON CONFLICT (day, flag, variant, source, group_key) DO UPDATE SET
                 subjects     = EXCLUDED.subjects,
                 rolled_up_at = EXCLUDED.rolled_up_at',
            [
                'day' => $day,
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * Roll up every day present in the ledger that the rollup does not already cover
     * completely, including today.
     *
     * Today is included on purpose. Excluding it would be the obvious choice — the day is
     * unfinished, so its numbers will change — but it would also mean a rollout cannot be
     * watched on the day it happens, which is the day anyone actually wants to watch it. The
     * upsert makes a partial day harmless: the next run corrects it.
     *
     * @return array<string,int> day => rollup rows written
     */
    public function rollupPending(): array
    {
        /** @var list<string> $days */
        $days = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT day FROM ' . $this->ledgerTable . ' ORDER BY day ASC'
        );

        $written = [];
        foreach ($days as $day) {
            $normalised = $this->normaliseDay($day);
            $written[$normalised] = $this->rollupDay($normalised);
        }

        return $written;
    }

    /**
     * Delete ledger days older than `$ledgerDays` and rollup days older than `$rollupDays`.
     *
     * Rolls up everything first — see the class docblock. The ledger delete is a prefix scan
     * on the primary key because `day` leads it, so this stays cheap as the table grows.
     *
     * @return array{ledger:int, rollup:int} rows deleted from each table
     */
    public function prune(int $ledgerDays, int $rollupDays): array
    {
        if ($ledgerDays < 1 || $rollupDays < 1) {
            throw new \InvalidArgumentException(
                'Retention must be at least one day for both tables; a zero or negative window '
                . 'would delete the day currently being written.'
            );
        }

        if ($rollupDays < $ledgerDays) {
            throw new \InvalidArgumentException(sprintf(
                'Rollup retention (%d days) must not be shorter than ledger retention (%d days). '
                . 'The rollup is the derived summary that outlives the raw rows; inverting that '
                . 'discards the only long-term record while keeping the expensive table.',
                $rollupDays,
                $ledgerDays,
            ));
        }

        // Everything still in the ledger is folded forward before any of it is deleted, so a
        // shortened window or a missed night cannot turn into a permanent hole in the rollup.
        $this->rollupPending();

        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));

        return [
            'ledger' => (int) $this->connection->executeStatement(
                'DELETE FROM ' . $this->ledgerTable . ' WHERE day < :cutoff',
                ['cutoff' => $this->cutoff($now, $ledgerDays)],
            ),
            'rollup' => (int) $this->connection->executeStatement(
                'DELETE FROM ' . $this->rollupTable . ' WHERE day < :cutoff',
                ['cutoff' => $this->cutoff($now, $rollupDays)],
            ),
        ];
    }

    private function cutoff(DateTimeImmutable $now, int $days): string
    {
        return $now->modify(sprintf('-%d days', $days))->format('Y-m-d');
    }

    /**
     * Normalise whatever the driver returned for a `date` column to `Y-m-d`.
     *
     * Drivers disagree here — some return a string, some a DateTime, and the string format
     * varies — and the value is about to be used as a parameter that must match the column
     * exactly. Normalising once is cheaper than discovering the mismatch as a rollup that
     * silently writes zero rows.
     */
    private function normaliseDay(mixed $day): string
    {
        if ($day instanceof DateTimeImmutable) {
            return $day->format('Y-m-d');
        }

        return (new DateTimeImmutable((string) $day))->format('Y-m-d');
    }
}
