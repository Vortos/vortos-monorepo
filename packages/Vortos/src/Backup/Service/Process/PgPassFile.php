<?php

declare(strict_types=1);

namespace Vortos\Backup\Service\Process;

use Vortos\Foundation\Process\SensitiveFile;
use Vortos\Foundation\Secret\SecretValue;

/**
 * A libpq password file for one launch, referenced by `PGPASSFILE`.
 *
 * PostgreSQL's own documentation discourages `PGPASSWORD` ("some operating systems allow non-root users
 * to see process environment variables") and recommends a 0600 password file; libpq refuses one with
 * looser permissions. The file carries a single wildcard entry because it exists for exactly one
 * connection of exactly one process.
 */
final class PgPassFile
{
    public static function for(string $password): SensitiveFile
    {
        $escaped = str_replace(['\\', ':'], ['\\\\', '\\:'], $password);

        return new SensitiveFile(SecretValue::fromString('*:*:*:*:' . $escaped . "\n"));
    }
}
