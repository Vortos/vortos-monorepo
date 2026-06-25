<?php

declare(strict_types=1);

namespace Vortos\Backup\Domain;

/**
 * The shape of a single backup artifact.
 *
 *  - {@see LogicalFull}   — a portable logical dump (pg_dump custom format / coarse RPO).
 *  - {@see PhysicalBase}  — a physical base backup (pg_basebackup) — the PITR anchor.
 *  - {@see WalSegment}    — one shipped WAL segment, replayed on top of a base for PITR.
 *  - {@see MongoArchive}  — a mongodump `--archive` stream (with oplog for consistency).
 */
enum BackupKind: string
{
    case LogicalFull = 'logical_full';
    case PhysicalBase = 'physical_base';
    case WalSegment = 'wal_segment';
    case MongoArchive = 'mongo_archive';

    /** A WAL segment is chained onto a base backup; it is not an independently restorable artifact. */
    public function isWalSegment(): bool
    {
        return $this === self::WalSegment;
    }

    /** A base/full artifact that retention treats as an independent restore point. */
    public function isRestorePoint(): bool
    {
        return $this !== self::WalSegment;
    }
}
