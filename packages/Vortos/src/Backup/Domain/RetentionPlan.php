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
     */
    public function __construct(
        public array $keep,
        public array $delete,
        public array $refused,
    ) {}

    public function isNoop(): bool
    {
        return $this->delete === [];
    }

    /** @return list<string> */
    public function deleteKeys(): array
    {
        return array_map(static fn (BackupArtifact $a): string => $a->storeKey, $this->delete);
    }

    /**
     * @return array{
     *   keep:list<string>,
     *   delete:list<string>,
     *   refused:list<array{key:string, reason:string}>
     * }
     */
    public function serialize(): array
    {
        return [
            'keep' => array_map(static fn (BackupArtifact $a): string => $a->id->value(), $this->keep),
            'delete' => array_map(static fn (BackupArtifact $a): string => $a->id->value(), $this->delete),
            'refused' => array_map(
                static fn (array $r): array => ['key' => $r['artifact']->id->value(), 'reason' => $r['reason']],
                $this->refused,
            ),
        ];
    }
}
