<?php

declare(strict_types=1);

namespace Vortos\Backup\Domain;

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
     */
    public function __construct(
        public array $keep,
        public array $delete,
        public array $refused,
        public int $keptWalCount = 0,
    ) {}

    public function isNoop(): bool
    {
        return $this->delete === [];
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
     * @return array{
     *   keep:list<string>,
     *   kept_wal_count:int,
     *   delete:list<string>,
     *   refused:list<array{key:string, reason:string}>
     * }
     */
    public function serialize(): array
    {
        return [
            'keep' => array_map(static fn (BackupArtifact $a): string => $a->id->value(), $this->keep),
            'kept_wal_count' => $this->keptWalCount,
            'delete' => array_map(static fn (BackupArtifact $a): string => $a->id->value(), $this->delete),
            'refused' => array_map(
                static fn (array $r): array => ['key' => $r['artifact']->id->value(), 'reason' => $r['reason']],
                $this->refused,
            ),
        ];
    }
}
