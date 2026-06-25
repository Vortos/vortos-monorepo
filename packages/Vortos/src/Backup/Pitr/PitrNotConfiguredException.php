<?php

declare(strict_types=1);

namespace Vortos\Backup\Pitr;

use Vortos\Backup\Domain\Exception\BackupException;

final class PitrNotConfiguredException extends BackupException
{
    /** @param list<string> $problems */
    public static function forProblems(array $problems): self
    {
        return new self(
            "Postgres is not configured for PITR:\n  - " . implode("\n  - ", $problems)
            . "\nEnable archive_mode=on, set archive_command, and wal_level=replica on the host.",
        );
    }
}
