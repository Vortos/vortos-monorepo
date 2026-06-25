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
}
