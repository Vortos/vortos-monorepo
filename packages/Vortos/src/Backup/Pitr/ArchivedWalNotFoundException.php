<?php

declare(strict_types=1);

namespace Vortos\Backup\Pitr;

use Vortos\Backup\Domain\Exception\BackupException;

/**
 * The requested WAL segment is not in the archive.
 *
 * Distinct from a real failure because Postgres relies on this outcome: at the end of recovery it
 * asks for the segment after the last one that exists, and a non-zero `restore_command` is exactly
 * how it learns the archive is exhausted and recovery is complete. Treating that as an error would
 * make every successful recovery look like a broken one.
 */
final class ArchivedWalNotFoundException extends BackupException
{
    public function __construct(public readonly string $walName)
    {
        parent::__construct(sprintf('WAL segment "%s" is not present in the archive.', $walName));
    }
}
