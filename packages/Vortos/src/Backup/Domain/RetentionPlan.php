<?php

declare(strict_types=1);

namespace Vortos\Backup\Domain;

use DateTimeImmutable;

/**
 * The pure, reviewable result of applying a {@see RetentionPolicy} to a set of
 * artifacts: what to keep, what to delete, and what was *refused* deletion (and why).
 *
 * `refused` is deliberately first-class: a misconfigured policy that would otherwise
 * have deleted the only good copy surfaces here as a visible, explained decision —
 * never a silent near-miss. `applyRetention` executes only the {@see $delete} set.
 */
final readonly class RetentionPlan
{
    /**
     * @param list<BackupArtifact>                     $keep
     * @param list<BackupArtifact>                     $delete
     * @param list<array{artifact:BackupArtifact, reason:string}> $refused
     * @param int                                      $keptWalCount retained WAL segments, counted rather
     *        than listed. This set grows without bound (a segment every few minutes, forever) and nothing
     *        downstream does anything with it but report how many there are, so materialising it would be
     *        an unbounded allocation in service of a log line. $keep therefore holds retained restore
     *        points only; {@see keptTotal()} is what an operator-facing "kept" figure should use.
     * @param ?DateTimeImmutable $walPruneAnchor the instant prunable WAL is measured against — segments
     *        strictly older than it are prunable. Null when nothing is prunable (no retained base to
     *        anchor on). $delete holds restore points ONLY: prunable WAL is the other unbounded set, so it
     *        is never listed here either. Before apply this is the anchor to prune to; after apply it is
     *        retained for reference. {@see enforce()} streams the WAL deletion from it.
     * @param int $walPruneCount prunable WAL segment count. Before apply, how many WILL be pruned; on the
     *        plan returned from apply, how many WERE actually deleted. Counted, never a hydrated list.
     */
    public function __construct(
        public array $keep,
        public array $delete,
        public array $refused,
        public int $keptWalCount = 0,
        public ?DateTimeImmutable $walPruneAnchor = null,
        public int $walPruneCount = 0,
    ) {}

    /**
     * Nothing to do only when there is neither a restore point to delete nor any prunable WAL. The
     * WAL half is why this is not `$this->delete === []`: after a lapse the delete set can be empty
     * while tens of thousands of WAL segments are waiting to be pruned, and treating that as a noop
     * is precisely how the backlog never drains.
     */
    public function isNoop(): bool
    {
        return $this->delete === [] && $this->walPruneCount === 0;
    }

    /** Everything removed: listed restore points plus streamed WAL segments. */
    public function deletedTotal(): int
    {
        return count($this->delete) + $this->walPruneCount;
    }

    /** Everything retained: listed restore points plus counted WAL segments. */
    public function keptTotal(): int
    {
        return count($this->keep) + $this->keptWalCount;
    }

    /** @return list<string> */
    public function deleteKeys(): array
    {
        return array_map(static fn (BackupArtifact $a): string => $a->storeKey, $this->delete);
    }

    /**
     * `keep` lists retained restore points only; retained WAL is reported as `kept_wal_count`.
     *
     * `delete` lists deleted restore points only; pruned WAL is reported as `wal_prune_count`, for
     * the same reason `keep` omits retained WAL — neither unbounded set is ever materialised to be
     * listed.
     *
     * @return array{
     *   keep:list<string>,
     *   kept_wal_count:int,
     *   delete:list<string>,
     *   wal_prune_count:int,
     *   refused:list<array{key:string, reason:string}>
     * }
     */
    public function serialize(): array
    {
        return [
            'keep' => array_map(static fn (BackupArtifact $a): string => $a->id->value(), $this->keep),
            'kept_wal_count' => $this->keptWalCount,
            'delete' => array_map(static fn (BackupArtifact $a): string => $a->id->value(), $this->delete),
            'wal_prune_count' => $this->walPruneCount,
            'refused' => array_map(
                static fn (array $r): array => ['key' => $r['artifact']->id->value(), 'reason' => $r['reason']],
                $this->refused,
            ),
        ];
    }
}
