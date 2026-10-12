<?php

declare(strict_types=1);

namespace Vortos\Backup\PgBackRest;

enum PgBackRestBackupType: string
{
    case Full = 'full';
    /** Everything changed since the last full — one hop from a full, so a restore never walks a chain. */
    case Differential = 'diff';
    case Incremental = 'incr';
}
