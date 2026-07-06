<?php

declare(strict_types=1);

namespace Vortos\Backup\Domain\Exception;

/**
 * Raised when a backup operation is invoked without an engine and none is configured.
 *
 * Fail closed: we never silently pick an engine, because guessing could dump — or restore over —
 * the wrong database. The operator must be explicit, via `--engine` or `VORTOS_BACKUP_ENGINE`.
 */
final class EngineNotConfiguredException extends BackupException
{
    /** @param list<string> $known */
    public static function create(array $known): self
    {
        return new self(sprintf(
            'No backup engine configured. Pass --engine=%s or set VORTOS_BACKUP_ENGINE.',
            $known === [] ? '<engine>' : implode('|', $known),
        ));
    }
}
