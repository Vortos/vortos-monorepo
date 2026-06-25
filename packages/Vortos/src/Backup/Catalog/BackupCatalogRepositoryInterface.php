<?php

declare(strict_types=1);

namespace Vortos\Backup\Catalog;

use Vortos\Backup\Domain\BackupArtifact;

/**
 * Append-only writer for the backup catalog. Records a verified artifact exactly once.
 * Immutability is enforced at the storage layer (a DB trigger), not by convention.
 */
interface BackupCatalogRepositoryInterface
{
    /** @throws BackupAlreadyExistsException when an artifact with the same id already exists */
    public function record(BackupArtifact $artifact): void;

    /** Remove a catalog row after its stored object has been deleted by retention. */
    public function forget(string $backupId): void;
}
