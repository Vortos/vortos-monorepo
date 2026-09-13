<?php

declare(strict_types=1);

namespace Vortos\Backup\Health;

/**
 * The WAL segment size of the cluster being archived.
 *
 * A port rather than a constant because `wal_segment_size` is fixed per cluster at initdb, and a
 * probe that divides by a guessed size stops being able to see the fault it exists for (FB-65).
 */
interface WalFileSizeResolverInterface
{
    /** Bytes per WAL segment. Throws when the size cannot be read. */
    public function segmentBytes(): int;
}
