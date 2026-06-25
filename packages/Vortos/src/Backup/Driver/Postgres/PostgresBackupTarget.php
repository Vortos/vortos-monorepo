<?php

declare(strict_types=1);

namespace Vortos\Backup\Driver\Postgres;

use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\BackupRequest;
use Vortos\Backup\Domain\CompressionCodec;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Domain\SourceRef;
use Vortos\Backup\Port\BackupStream;
use Vortos\Backup\Port\BackupTargetInterface;
use Vortos\Backup\Port\Capability\BackupTargetCapability;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;
use Vortos\OpsKit\Driver\Exception\UnsupportedCapabilityException;

/**
 * The Postgres backup target.
 *
 *  - {@see BackupKind::LogicalFull}  → `pg_dump` custom format (internally compressed,
 *    `PGDMP` magic) — portable, restorable on any compatible cluster.
 *  - {@see BackupKind::PhysicalBase} → `pg_basebackup` tar — the PITR anchor.
 *
 * WAL segments are shipped by {@see \Vortos\Backup\Pitr\PostgresWalArchiver} (the
 * host `archive_command`), not produced through `dump()`.
 */
#[AsDriver('postgres')]
final class PostgresBackupTarget implements BackupTargetInterface
{
    public function __construct(private readonly PostgresProcessFactory $processes)
    {
    }

    public function engine(): DatabaseEngine
    {
        return DatabaseEngine::Postgres;
    }

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            BackupTargetCapability::ConsistentSnapshot->value => true,
            BackupTargetCapability::FromReplica->value => true,
            BackupTargetCapability::Pitr->value => true,
            BackupTargetCapability::Incremental->value => false,
            BackupTargetCapability::Streaming->value => true,
        ]);
    }

    public function dump(BackupRequest $request): BackupStream
    {
        return match ($request->kind) {
            BackupKind::LogicalFull => $this->logical($request),
            BackupKind::PhysicalBase => $this->physical($request),
            default => throw UnsupportedCapabilityException::for('postgres', 'kind:' . $request->kind->value),
        };
    }

    private function logical(BackupRequest $request): BackupStream
    {
        [$stdout, $guard] = $this->processes->pgDump($request->fromReplica);

        // pg_dump custom format carries its own internal compression; the on-disk magic
        // is PGDMP, so the external codec is None.
        return new BackupStream($stdout, DatabaseEngine::Postgres, BackupKind::LogicalFull, CompressionCodec::None, SourceRef::none(), $guard);
    }

    private function physical(BackupRequest $request): BackupStream
    {
        [$stdout, $guard] = $this->processes->pgBaseBackup($request->fromReplica);

        return new BackupStream($stdout, DatabaseEngine::Postgres, BackupKind::PhysicalBase, CompressionCodec::None, SourceRef::none(), $guard);
    }
}
