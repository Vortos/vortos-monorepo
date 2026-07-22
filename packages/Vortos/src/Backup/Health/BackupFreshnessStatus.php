<?php

declare(strict_types=1);

namespace Vortos\Backup\Health;

/**
 * Whether the most recent catalogued backup for a target is recent enough to be trusted.
 *
 * {@see NeverRun} is deliberately distinct from {@see Stale}: "we have never taken a backup of this"
 * and "our backups stopped" are different incidents with different responses (a misconfiguration
 * versus a regression), and collapsing them loses that signal on the one occasion it matters most.
 */
enum BackupFreshnessStatus: string
{
    /** A backup exists and is within the derived staleness threshold. */
    case Fresh = 'fresh';

    /** A backup exists but is older than the threshold — the cadence has stopped. */
    case Stale = 'stale';

    /** No catalogued backup at all for this engine + environment. */
    case NeverRun = 'never_run';

    public function isHealthy(): bool
    {
        return $this === self::Fresh;
    }
}
