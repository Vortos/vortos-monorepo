<?php

declare(strict_types=1);

namespace Vortos\Backup\Pitr;

use Vortos\Backup\Domain\BackupArtifact;

/**
 * The ordered set of WAL segments that replay on top of a physical base backup to
 * reach a point in time — the input a Block-20 restore drill needs for PITR.
 *
 * Pure: it sorts segments by their (lexicographically time-ordered) id and exposes a
 * simple "is this chain contiguous from the base" check so a missing segment is
 * detected before a restore is attempted, not during one.
 */
final readonly class WalChain
{
    /** @var list<BackupArtifact> */
    public array $segments;

    /**
     * @param list<BackupArtifact> $segments
     */
    public function __construct(
        public BackupArtifact $base,
        array $segments,
    ) {
        usort($segments, static fn (BackupArtifact $a, BackupArtifact $b): int => $a->createdAt <=> $b->createdAt);
        $this->segments = $segments;
    }

    /**
     * WAL segments taken at or after the base backup — the only ones it can replay.
     *
     * @return list<BackupArtifact>
     */
    public function replayable(): array
    {
        return array_values(array_filter(
            $this->segments,
            fn (BackupArtifact $s): bool => $s->createdAt >= $this->base->createdAt,
        ));
    }

    public function isEmpty(): bool
    {
        return $this->replayable() === [];
    }
}
