<?php

declare(strict_types=1);

namespace Vortos\Backup\Catalog;

use Vortos\Backup\Domain\BackupArtifact;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\DatabaseEngine;

/**
 * Fast, queryable read side over the catalog — the input to listing, retention, and
 * the `backup_age_seconds` SLO metric. Cheaper and more reliable than re-listing the
 * store on every query.
 */
interface BackupCatalogReadModelInterface
{
    public function byId(string $backupId): ?BackupArtifact;

    /**
     * All artifacts for an engine+environment (optionally a single kind), newest first.
     *
     * @return list<BackupArtifact>
     */
    public function list(DatabaseEngine $engine, string $environment, ?BackupKind $kind = null): array;

    /** The most recent verified artifact for an engine+environment, or null if none. */
    public function latest(DatabaseEngine $engine, string $environment): ?BackupArtifact;

    /**
     * The most recent artifact of one of the given kinds.
     *
     * Exists because {@see self::latest()} answers "the newest row", which stopped being a useful
     * question the moment continuous WAL archiving was switched on: a `wal_segment` lands roughly
     * every sixty seconds, so the newest row is almost always a single WAL segment rather than
     * anything you could restore from. A restore drill asking for "the latest backup" would pick
     * one up and either fail, or — far worse — report a pass having proved nothing.
     *
     * @param non-empty-list<BackupKind> $kinds
     */
    public function latestOfKind(DatabaseEngine $engine, string $environment, array $kinds): ?BackupArtifact;
}
