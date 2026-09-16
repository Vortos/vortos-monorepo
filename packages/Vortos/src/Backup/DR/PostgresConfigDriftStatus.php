<?php

declare(strict_types=1);

namespace Vortos\Backup\DR;

enum PostgresConfigDriftStatus: string
{
    case Clean = 'clean';
    case Drifted = 'drifted';
    case Undeclared = 'undeclared';
    /** The settings could not be read at all (connection failure); the database's own probes page for that. */
    case Indeterminate = 'indeterminate';
    /**
     * The role WATCHES the cluster (superuser or pg_read_all_settings) but still cannot read pg_file_settings, so
     * every file-sourced setting would look undeclared. A configuration defect of the check itself: it pages,
     * because a drift check that cannot see sources and stays quiet is indistinguishable from one that passed.
     */
    case Unverifiable = 'unverifiable';
    /**
     * This node's role is not a cluster-watching one at all — the least-privilege application role, by design.
     * Reported, never paged: the node that does watch (the backup sidecar, holding pg_read_all_settings) answers
     * the same question, and paging here would fire on every colour for a database that is perfectly configured.
     */
    case NotWatchedHere = 'not_watched_here';
}
