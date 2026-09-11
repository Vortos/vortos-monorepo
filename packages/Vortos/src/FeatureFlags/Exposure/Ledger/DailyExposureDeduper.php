<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Exposure\Ledger;

use Psr\Clock\ClockInterface;
use Throwable;
use Vortos\Cache\Contract\AtomicCacheInterface;

/**
 * Collapses many evaluations into the ledger's one-row-per-subject-per-flag-per-day grain,
 * across requests and across workers.
 *
 * {@see \Vortos\FeatureFlags\Exposure\ExposureReportingFlagRegistry} already dedupes within a
 * single request, which is enough to stop a flag read in a loop from reporting thousands of
 * times. It cannot do more than that: its dedupe set is cleared on reset, by design, so the
 * same subject reading the same flag on their next page view is a fresh exposure to it. Over
 * a day of normal use that is one row per page view, which is one to two orders of magnitude
 * more rows than the grain the statistics actually need.
 *
 * This deduper is the cross-request half. A shared atomic `setNx` is the whole mechanism:
 * the first worker to claim `(day, subject, flag, variant)` writes the row, every other
 * caller that day is told it is not the first and drops it.
 *
 * ## Failing open, on purpose
 *
 * If the cache is unreachable every exposure is treated as new. That is the correct
 * direction: the database's primary key is the actual source of truth on uniqueness, so a
 * duplicate that slips through is absorbed by `ON CONFLICT DO NOTHING` and costs one wasted
 * insert attempt. Failing closed would instead drop exposures during a Redis blip, and a
 * gap in an experiment's exposure log is unrecoverable and invisible — you cannot tell a
 * subject who was never exposed from one whose exposure was eaten.
 *
 * That asymmetry is the reason {@see LedgerExposure::dedupeKey()} is required to mirror the
 * table's primary key exactly. If the two disagreed, this fallback would stop being
 * harmless.
 */
final readonly class DailyExposureDeduper
{
    /** Namespaced so a shared Redis cannot collide with another subsystem's keys. */
    public const KEY_PREFIX = 'vortos:flagexp:';

    /**
     * Grace added to the TTL so a key spans the full day plus clock skew between workers.
     * Without it a key minted at 23:59:59 would expire a second later and the first
     * evaluation after midnight would be counted twice — into two different days, so the
     * database would not catch it either.
     */
    public const TTL_GRACE_SECONDS = 3600;

    /**
     * @param AtomicCacheInterface|null $cache absent when the deployment wires no atomic cache.
     *                                         The ledger still works — every exposure is treated
     *                                         as new and the primary key absorbs the duplicates —
     *                                         it just writes more rows than it needs to. Losing
     *                                         the pre-filter is a cost problem; losing the ledger
     *                                         would be a correctness one, so this degrades rather
     *                                         than refuses.
     */
    public function __construct(
        private ?AtomicCacheInterface $cache,
        private ClockInterface $clock,
    ) {}

    /**
     * True when this is the first time today that this subject saw this result for this flag.
     *
     * @param LedgerExposure $exposure already carries the UTC day it belongs to; the TTL is
     *                                 derived from that same day so the key cannot outlive it
     */
    public function isFirstToday(LedgerExposure $exposure): bool
    {
        if ($this->cache === null) {
            return true;
        }

        try {
            return $this->cache->setNx(
                self::KEY_PREFIX . hash('xxh128', $exposure->dedupeKey()),
                1,
                $this->secondsLeftInDay(),
            );
        } catch (Throwable) {
            // Fail open — see the class docblock. The primary key is the real guard.
            return true;
        }
    }

    /**
     * Seconds until the end of the current UTC day, plus grace.
     *
     * UTC and not local time: the ledger's `day` column is UTC, and a TTL measured against a
     * different day boundary than the column would let one calendar day hold two dedupe
     * windows in some timezones and none in others.
     */
    private function secondsLeftInDay(): int
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $endOfDay = $now->setTime(23, 59, 59);

        return max(1, $endOfDay->getTimestamp() - $now->getTimestamp() + self::TTL_GRACE_SECONDS);
    }
}
