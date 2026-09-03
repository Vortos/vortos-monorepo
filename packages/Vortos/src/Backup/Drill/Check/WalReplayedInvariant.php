<?php

declare(strict_types=1);

namespace Vortos\Backup\Drill\Check;

use Vortos\Backup\Drill\InvariantCheck;
use Vortos\Backup\Drill\InvariantResult;
use Vortos\Backup\Pitr\PitrRecoveryRecorder;

/**
 * Asserts that a point-in-time drill actually rolled the log forward, rather than merely starting
 * the base backup up.
 *
 * THIS IS THE CHECK THE WHOLE FEATURE TURNS ON. Restoring a `physical_base` and booting it is not
 * point-in-time recovery — it is a restore to the instant the base was taken, which is precisely
 * what PITR exists to improve on. And it is indistinguishable from a successful recovery from the
 * outside: the cluster starts, the schema is there, the row counts are plausible, every other
 * invariant passes. A drill that stopped there would report a green "PITR verified" while proving
 * that the entire WAL pipeline — the part carrying the last week of writes, and the part that has
 * actually been rewritten twice by compression and encryption changes — is completely untested.
 *
 * So the evidence is checked as data, and three separate ways, because each covers a different way
 * the replay can be hollow:
 *
 *  - **Segments served.** At least one segment must have been fetched from the archive and handed
 *    to recovery. Zero means the base started up on its own and the archive was never touched.
 *  - **The log moved.** The end LSN must be strictly beyond the redo start LSN. A recovery can
 *    request a segment, be handed one, and still replay nothing useful; comparing the positions
 *    PostgreSQL itself reported is what proves work happened.
 *  - **The archive was exhausted.** Recovery must have stopped because the store ran out of
 *    segments, not because the feeder gave up or a target was reached. This is what makes the
 *    result "recovered to the last archived instant" rather than "recovered to somewhere".
 *
 * The recorder is read rather than the database, because the interesting facts are gone by the time
 * anything can connect: `pg_last_wal_replay_lsn()` reads NULL once a cluster has promoted, so a
 * check that ran afterwards could not tell a full replay from no replay at all.
 */
final class WalReplayedInvariant implements InvariantCheck
{
    public function __construct(
        private readonly PitrRecoveryRecorder $recorder,
        /**
         * Whether reaching the end of the archive is required to pass.
         *
         * On by default: the point of the weekly drill is to prove recovery to the most recent
         * instant the archive can offer. Configurable because a drill deliberately aimed at an
         * earlier recovery target legitimately stops short, and should not be forced to fail.
         */
        private readonly bool $requireEndOfWal = true,
    ) {}

    public function name(): string
    {
        return 'wal_replayed';
    }

    public function check(array $connectionParams): InvariantResult
    {
        $outcome = $this->recorder->outcome();

        if ($outcome === null) {
            // Not "no WAL, therefore fine". This invariant is only ever attached to a point-in-time
            // drill, so an absent recording means the recovery path did not run — and reporting a
            // pass here would be the exact vacuous green it exists to prevent.
            return InvariantResult::fail(
                $this->name(),
                'no point-in-time recovery was recorded — the drill did not perform a WAL replay',
            );
        }

        $failures = [];

        if ($outcome->segmentsServed < 1) {
            $failures[] = 'no WAL segments were served from the archive: the base backup started up '
                . 'without replaying anything, which is a restore to the base backup\'s own instant';
        }

        if (!$outcome->replayed()) {
            $failures[] = sprintf(
                'the log did not advance (redo start %s, end %s)',
                $outcome->startLsn ?? 'unknown',
                $outcome->endLsn ?? 'unknown',
            );
        }

        if ($this->requireEndOfWal && !$outcome->reachedEndOfWal) {
            $failures[] = 'recovery stopped before the archive was exhausted, so this does not prove '
                . 'recovery to the most recent archived instant';
        }

        if ($failures !== []) {
            return InvariantResult::fail($this->name(), implode('; ', $failures));
        }

        return InvariantResult::pass($this->name(), $outcome->summary());
    }
}
