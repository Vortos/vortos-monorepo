<?php

declare(strict_types=1);

namespace Vortos\Backup\Catalog;

use DateTimeImmutable;
use Vortos\Backup\Domain\BackupArtifact;
use Vortos\Backup\Domain\DatabaseEngine;

/**
 * The catalog seam that retention planning reasons over — deliberately narrower than
 * {@see BackupCatalogReadModelInterface}.
 *
 * WHY THIS EXISTS SEPARATELY
 * --------------------------
 * Retention used to plan from `BackupCatalogReadModelInterface::list()`, which hydrates every
 * artifact for an engine+environment into memory. Restore points are naturally bounded (a base
 * backup a day), but WAL segments are not: one is shipped every few minutes, forever. Against a
 * fixed `memory_limit` that is not a slow leak, it is a scheduled outage — and it arrived, taking
 * the whole backup lifecycle down with it, because retention runs before the backup steps.
 *
 * Raising the memory limit only moves the date. The fix is to make the unbounded set
 * *unrepresentable* on this path, which is what these three methods do:
 *
 *   - restore points are bounded, so they are still returned whole;
 *   - WAL to delete is unbounded after a lapse, so it is STREAMED one artifact at a time and sized
 *     with a COUNT — never returned as a list;
 *   - WAL retained is unbounded and only ever reported as a number, so it is only ever counted.
 *
 * There is deliberately no "give me every WAL segment" method, and the prunable slice is a
 * generator rather than an array for the same reason: an adapter cannot reintroduce the bug by
 * omission, and a reviewer does not have to trust that a caller remembered to be careful. An
 * earlier version of this interface returned the prunable WAL as a list "bounded by the prune";
 * that bound did not hold when retention lapsed, and it is the defect this shape now forecloses.
 *
 * It is a separate interface rather than three more methods on the read model because the read
 * model has six implementations across the suite, most of them anonymous probe fixtures with no
 * interest in retention. Widening the shared interface breaks all of them to serve one consumer;
 * segregating it touches exactly the two classes that plan retention.
 */
interface RetentionCatalogInterface
{
    /**
     * Every non-WAL artifact for an engine+environment, newest first — the input to the GFS policy.
     *
     * Bounded by the restore-point cadence, so returning the whole set is safe.
     *
     * @return list<BackupArtifact>
     */
    public function listRestorePoints(DatabaseEngine $engine, string $environment): array;

    /**
     * The prunable WAL segments — those created strictly before $before — streamed oldest first,
     * one at a time, never accumulated.
     *
     * Strictly before, because a segment created at exactly the oldest retained base backup's
     * instant is still needed to replay from it.
     *
     * WHY A GENERATOR AND NOT A LIST. This is the set that took production down. "Bounded by the
     * size of the prune" was the assumption behind the old list-returning method, and it was false:
     * the prune is only small when retention runs on time. Miss a few days — or run the very first
     * pass after this bug — and the prunable set is every segment shipped in that gap, tens of
     * thousands of rows, hydrated at once against a fixed memory_limit. The set is unbounded in
     * exactly the way the retained set is, so it is handled the same way: the database pages through
     * it and the caller sees one artifact at a time. An implementation MUST keyset-paginate (never
     * OFFSET) so that deleting a yielded row mid-stream cannot make the cursor skip its successor.
     *
     * @param positive-int $batchSize how many rows each underlying page fetches; caps peak memory.
     * @return iterable<BackupArtifact>
     */
    public function iterateWalOlderThan(
        DatabaseEngine $engine,
        string $environment,
        DateTimeImmutable $before,
        int $batchSize = 1000,
    ): iterable;

    /**
     * How many WAL segments are prunable: those created strictly before $before.
     *
     * The count the plan reports and the noop check reads, resolved in the database so the prunable
     * set is never hydrated just to be sized. Zero when $before is null — no retained base means no
     * anchor to prune against, so nothing is prunable.
     */
    public function countWalOlderThan(
        DatabaseEngine $engine,
        string $environment,
        ?DateTimeImmutable $before,
    ): int;

    /**
     * How many WAL segments are being retained: those created at or after $from, or all of them
     * when $from is null (no retained base backup ⇒ nothing may be pruned).
     *
     * Returns a count and never artifacts: this is the unbounded set, and the only thing anything
     * downstream does with it is report the number.
     */
    public function countWalFrom(
        DatabaseEngine $engine,
        string $environment,
        ?DateTimeImmutable $from,
    ): int;
}
