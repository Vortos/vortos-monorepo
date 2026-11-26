<?php

declare(strict_types=1);

namespace Vortos\Backup\PgBackRest;

/**
 * Which of pgBackRest's repositories a command acts on.
 *
 *  - Primary: the fast one, close to the server, that every restore reaches for first.
 *  - Offsite: a second copy at another provider, on versioned storage with a compliance-mode lock
 *    ({@see PgBackRestOffsite}). It exists for the day the server, or the key on it, is turned against
 *    the backups: whatever is done with that key, the copy as it stood before can still be read
 *    (pgBackRest's --repo-target-time).
 */
enum PgBackRestRepositoryRole: string
{
    case Primary = 'primary';
    case Offsite = 'offsite';
}
