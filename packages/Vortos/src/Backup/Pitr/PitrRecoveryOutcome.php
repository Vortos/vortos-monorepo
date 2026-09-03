<?php

declare(strict_types=1);

namespace Vortos\Backup\Pitr;

/**
 * What a point-in-time recovery actually did — the evidence a PITR drill is run to produce.
 *
 * This exists because "the restore returned without throwing" is not evidence of anything. A base
 * backup laid into a data directory and started with no WAL at all comes up perfectly happily at
 * the instant the base was taken; so does one whose `restore_command` silently failed on its first
 * request. Both look exactly like a successful recovery from the outside, and both mean the archive
 * is unrestorable. The distinguishing facts are how far the log actually replayed and how many
 * segments were served to do it, so those are recorded as data rather than inferred from the
 * absence of an exception — see {@see \Vortos\Backup\Drill\Check\WalReplayedInvariant}, which turns
 * them into a pass or a fail.
 */
final readonly class PitrRecoveryOutcome
{
    /**
     * @param int         $segmentsServed  WAL segments fetched from the archive and handed to recovery
     * @param string|null $startLsn        redo start, read from the base backup's own control data
     * @param string|null $endLsn          last LSN replayed before the cluster promoted
     * @param string|null $lastSegment     the final segment recovery consumed
     * @param bool        $reachedEndOfWal recovery stopped because the archive ran out, not because it gave up
     */
    public function __construct(
        public int $segmentsServed,
        public ?string $startLsn,
        public ?string $endLsn,
        public ?string $lastSegment,
        public bool $reachedEndOfWal,
        public int $recoveryMs,
        public ?string $timeline = null,
    ) {}

    /**
     * Did the log actually move?
     *
     * LSNs are hex `X/Y` strings, compared as integers rather than lexically: `10/0` is far beyond
     * `9/FFFFFFFF` but sorts before it as text, and a string comparison would report a recovery
     * that crossed that boundary as having gone backwards.
     */
    public function replayed(): bool
    {
        if ($this->segmentsServed < 1) {
            return false;
        }

        if ($this->startLsn === null || $this->endLsn === null) {
            return false;
        }

        return self::lsnToInt($this->endLsn) > self::lsnToInt($this->startLsn);
    }

    public static function lsnToInt(string $lsn): int
    {
        $parts = explode('/', $lsn, 2);
        if (\count($parts) !== 2) {
            return 0;
        }

        return (\hexdec($parts[0]) << 32) | \hexdec($parts[1]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'segments_served' => $this->segmentsServed,
            'start_lsn' => $this->startLsn,
            'end_lsn' => $this->endLsn,
            'last_segment' => $this->lastSegment,
            'reached_end_of_wal' => $this->reachedEndOfWal,
            'recovery_ms' => $this->recoveryMs,
            'timeline' => $this->timeline,
        ];
    }

    /**
     * Read the segment count back out of a {@see summary()} string.
     *
     * The pair exists so the format is written and parsed in ONE class, with a round-trip test
     * holding them together. The drill report stores invariant results as free text, and the
     * metrics collector needs the count from them; without this the format would be duplicated as a
     * regex in a different package, and the day the wording changed the series would silently stop
     * being emitted rather than fail.
     */
    public static function segmentsFromSummary(string $summary): ?int
    {
        return preg_match('/^(\d+) WAL segments replayed/', $summary, $m) === 1 ? (int) $m[1] : null;
    }

    public function summary(): string
    {
        return sprintf(
            '%d WAL segments replayed, %s → %s%s, %s in %dms',
            $this->segmentsServed,
            $this->startLsn ?? '?',
            $this->endLsn ?? '?',
            $this->lastSegment !== null ? ' (last ' . $this->lastSegment . ')' : '',
            $this->reachedEndOfWal ? 'reached end of archive' : 'stopped short of end of archive',
            $this->recoveryMs,
        );
    }
}
