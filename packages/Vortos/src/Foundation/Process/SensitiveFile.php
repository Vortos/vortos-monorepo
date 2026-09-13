<?php

declare(strict_types=1);

namespace Vortos\Foundation\Process;

use Vortos\Foundation\Secret\SecretValue;

/**
 * A secret delivered to a child as a file: written 0600 into a 0700 directory private to one launch,
 * referenced by PATH (which is not secret), and overwritten and removed when the process ends.
 *
 * The mechanism tools document for exactly this — `mongodump --config`, libpq's `PGPASSFILE`,
 * `docker --config` — because argv is readable by every local user through /proc/<pid>/cmdline and
 * ends up in `ps`, audit logs and crash reports.
 *
 * Usable as an argv element (rendered as `$argumentPrefix . $path`) or as an env value (the path).
 */
final readonly class SensitiveFile
{
    public function __construct(
        public SecretValue $contents,
        public string $argumentPrefix = '',
    ) {}
}
