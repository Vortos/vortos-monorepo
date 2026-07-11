<?php

declare(strict_types=1);

namespace Vortos\Audit\Retention;

/**
 * The archival frontier for one chain: everything up to and including {@see lastSequence}
 * has been written to cold storage and purged from the hot table. Its {@see lastContentHash}
 * is the prev_hash the remaining hot chain verifies against — so a purged prefix never
 * breaks verification of what's left.
 */
final readonly class AuditCheckpoint
{
    public function __construct(
        public string             $chainKey,
        public int                $lastSequence,
        public string             $lastContentHash,
        public \DateTimeImmutable $archivedAt,
        public string             $objectKey,
        public int                $recordCount,
    ) {}
}
