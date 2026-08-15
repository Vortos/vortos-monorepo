<?php

declare(strict_types=1);

namespace Vortos\Backup\Health;

/**
 * What WAL archiving actually cost over a window, and whether that cost is proportionate.
 *
 * The measurement exists because the failure it detects is invisible to every other check. WAL was
 * archived successfully, restored successfully, verified successfully, and consumed ~87x the storage
 * it needed to for three weeks. `pg_stat_archiver.failed_count` was 0 the whole time. Freshness was
 * green. The drill passed. Nothing was broken in any sense a probe was looking for — the segments
 * were simply 98.8% zero padding, because `archive_timeout` forces a switch on a clock and an
 * archived segment is a full 16 MiB however little log it holds.
 *
 * So the thing worth watching is not success but SIZE, and specifically two independent quantities
 * that fail for different reasons and want different responses:
 *
 *  - {@see $meanStoredBytes} — what one segment costs at rest. Compression working puts this in the
 *    hundreds of KB; compression silently off puts it at 16 MiB. This is the regression detector,
 *    and it is deliberately a MEAN rather than a ratio against real WAL, because it needs no second
 *    data source and is therefore available on any host that can read the catalog.
 *
 *  - {@see $totalStoredBytes} — the absolute daily bill. Compression cannot fix a genuine write
 *    explosion, and a system that starts generating ten times the WAL it used to is a different
 *    problem with a different fix. Watching only the ratio would call that healthy.
 */
final readonly class WalEfficiency
{
    /** A Postgres WAL segment at the default `wal_segment_size`. The denominator the padding is measured against. */
    public const SEGMENT_BYTES = 16 * 1024 * 1024;

    public function __construct(
        public string $environment,
        public int $segments,
        public int $totalStoredBytes,
        public int $windowHours,
    ) {}

    public function meanStoredBytes(): float
    {
        return $this->segments === 0 ? 0.0 : $this->totalStoredBytes / $this->segments;
    }

    /**
     * How much smaller a stored segment is than the 16 MiB it represents.
     *
     * 1.0 means segments are shipped at full size — the pre-compression state. Reported rather than
     * asserted here; the threshold belongs to the probe, which is where an operator can see it.
     */
    public function compressionRatio(): float
    {
        $mean = $this->meanStoredBytes();

        return $mean <= 0.0 ? 0.0 : self::SEGMENT_BYTES / $mean;
    }

    /** Extrapolated so the number reads the way a bill does, independent of the window chosen. */
    public function projectedDailyBytes(): float
    {
        return $this->windowHours <= 0 ? 0.0 : $this->totalStoredBytes * (24 / $this->windowHours);
    }

    /**
     * True when the window holds too little to judge.
     *
     * A quiet hour is not evidence of a regression, and reporting one as a failure trains people to
     * ignore the probe — which is how the original fault survived: the signals that existed were not
     * trusted enough to act on.
     */
    public function indeterminate(): bool
    {
        return $this->segments < 10;
    }
}
