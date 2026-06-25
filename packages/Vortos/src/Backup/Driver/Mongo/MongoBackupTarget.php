<?php

declare(strict_types=1);

namespace Vortos\Backup\Driver\Mongo;

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
 * The Mongo backup target: `mongodump --archive --gzip` (consistent via `--oplog`).
 *
 * Mongo does **not** support PITR in this driver — it honestly declares `pitr=false`,
 * and asking it for a physical/base/WAL kind raises {@see UnsupportedCapabilityException}
 * (the TCK asserts this — no silent degradation).
 */
#[AsDriver('mongo')]
final class MongoBackupTarget implements BackupTargetInterface
{
    public function __construct(private readonly MongoProcessFactory $processes)
    {
    }

    public function engine(): DatabaseEngine
    {
        return DatabaseEngine::Mongo;
    }

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            BackupTargetCapability::ConsistentSnapshot->value => true,
            BackupTargetCapability::FromReplica->value => true,
            BackupTargetCapability::Pitr->value => false,
            BackupTargetCapability::Incremental->value => false,
            BackupTargetCapability::Streaming->value => true,
        ]);
    }

    public function dump(BackupRequest $request): BackupStream
    {
        if ($request->kind !== BackupKind::MongoArchive) {
            throw UnsupportedCapabilityException::for('mongo', 'kind:' . $request->kind->value);
        }

        [$stdout, $guard] = $this->processes->mongodump($request->consistentSnapshot);

        return new BackupStream($stdout, DatabaseEngine::Mongo, BackupKind::MongoArchive, CompressionCodec::Gzip, SourceRef::none(), $guard);
    }
}
