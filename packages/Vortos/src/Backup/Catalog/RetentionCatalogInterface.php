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
 *   - WAL to delete is bounded by what is actually being pruned, so the query, not PHP, decides;
 *   - WAL retained is unbounded and only ever reported as a number, so it is only ever counted.
 *
 * There is deliberately no "give me every WAL segment" method. An adapter cannot reintroduce the
 * bug by omission, and a reviewer does not have to trust that a caller remembered to be careful.
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
     * WAL segments created strictly before $before, newest first.
     *
     * Strictly before, because a segment created at exactly the oldest retained base backup's
     * instant is still needed to replay from it. The result is bounded by the size of the prune.
     *
     * @return list<BackupArtifact>
     */
    public function listWalOlderThan(
        DatabaseEngine $engine,
        string $environment,
        DateTimeImmutable $before,
    ): array;

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
