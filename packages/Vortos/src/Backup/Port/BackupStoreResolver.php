<?php

declare(strict_types=1);

namespace Vortos\Backup\Port;

use Vortos\Backup\Domain\BackupArtifact;
use Vortos\Backup\Domain\BackupKind;

/**
 * Answers "which store does this belong in / come from" once, so nothing else has to guess.
 *
 * There are two stores at most: the primary, and optionally a separate one for WAL segments (see
 * {@see \Vortos\Backup\Config\BackupConfig::walStore()} for why they want different buckets).
 *
 * READING AND WRITING ARE DIFFERENT QUESTIONS
 * -------------------------------------------
 * Writing resolves from the KIND. That is a decision about where a new artifact should go, and
 * configuration is the right authority for it.
 *
 * Reading resolves from the ARTIFACT, and the artifact's own recorded store wins. Configuration is
 * not a safe authority for something written in the past: point walStore() somewhere new and every
 * segment already shipped would suddenly be looked for in a bucket that never held it. That failure
 * surfaces during a restore, which is the worst imaginable moment to discover it.
 *
 * A null storeId means the row predates the column, and for those the primary store is not a guess —
 * it is where they demonstrably are, because it was the only store that existed.
 */
final readonly class BackupStoreResolver
{
    public function __construct(
        private string $primaryStoreKey,
        private ?string $walStoreKey = null,
    ) {}

    /** Where a newly created artifact of this kind should be written. */
    public function forKind(BackupKind $kind): string
    {
        if ($kind->isWalSegment() && $this->walStoreKey !== null) {
            return $this->walStoreKey;
        }

        return $this->primaryStoreKey;
    }

    /** Where an existing artifact actually lives — its recorded store wins over configuration. */
    public function forArtifact(BackupArtifact $artifact): string
    {
        return $artifact->storeId ?? $this->primaryStoreKey;
    }

    /** Whether WAL is kept apart from restore points at all. */
    public function hasDedicatedWalStore(): bool
    {
        return $this->walStoreKey !== null;
    }

    public function primaryStoreKey(): string
    {
        return $this->primaryStoreKey;
    }
}
