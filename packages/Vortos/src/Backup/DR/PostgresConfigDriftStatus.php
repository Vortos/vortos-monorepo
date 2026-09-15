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
     * The settings were read but the connection role cannot see where they come from (no pg_read_all_settings),
     * so every file-sourced setting would look undeclared. A configuration defect of the check itself: it pages,
     * because a drift check that cannot see sources and stays quiet is indistinguishable from one that passed.
     */
    case Unverifiable = 'unverifiable';
}
