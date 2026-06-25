<?php

declare(strict_types=1);

namespace Vortos\Backup\Port\Capability;

use Vortos\OpsKit\Driver\Capability\CapabilityKey;

/**
 * The capabilities a backup *target* (dump source) may declare.
 *
 * Validated at config time and asserted by the TCK, so a target can never silently
 * pretend to support PITR or replica-sourced dumps it cannot actually do.
 */
enum BackupTargetCapability: string implements CapabilityKey
{
    /** Produces a transaction-consistent snapshot (e.g. pg snapshot / mongodump --oplog). */
    case ConsistentSnapshot = 'consistent_snapshot';

    /** Can source the dump from a read replica / secondary, sparing the primary. */
    case FromReplica = 'from_replica';

    /** Supports point-in-time recovery (physical base backup + WAL shipping). */
    case Pitr = 'pitr';

    /** Supports incremental / differential backups. */
    case Incremental = 'incremental';

    /** Emits the dump as a stream (no full buffering to memory/disk). */
    case Streaming = 'streaming';

    public function key(): string
    {
        return $this->value;
    }
}
