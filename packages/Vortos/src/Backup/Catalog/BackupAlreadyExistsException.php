<?php

declare(strict_types=1);

namespace Vortos\Backup\Catalog;

use Vortos\Backup\Domain\Exception\BackupException;

final class BackupAlreadyExistsException extends BackupException
{
    public static function forId(string $backupId): self
    {
        return new self(sprintf("A backup with id '%s' is already cataloged (catalog is append-only).", $backupId));
    }
}
