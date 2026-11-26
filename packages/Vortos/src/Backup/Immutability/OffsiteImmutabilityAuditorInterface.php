<?php

declare(strict_types=1);

namespace Vortos\Backup\Immutability;

use Vortos\Backup\PgBackRest\PgBackRestOffsite;

/**
 * Reads back, from the provider, whether the off-site bucket still carries the lock that makes it
 * immutable. Declared is not configured: a lock turned off, shortened or switched to a mode that can be
 * bypassed leaves every backup looking healthy until the day it is needed.
 */
interface OffsiteImmutabilityAuditorInterface
{
    public function audit(PgBackRestOffsite $offsite): ImmutabilityAudit;
}
