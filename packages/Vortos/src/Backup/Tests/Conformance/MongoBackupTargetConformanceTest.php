<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Conformance;

use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Driver\Mongo\MongoBackupTarget;
use Vortos\Backup\Driver\Mongo\MongoProcessFactory;
use Vortos\Backup\Testing\BackupTargetConformanceTestCase;
use Vortos\OpsKit\Driver\DriverInterface;

final class MongoBackupTargetConformanceTest extends BackupTargetConformanceTestCase
{
    protected function createDriver(): DriverInterface
    {
        return new MongoBackupTarget(new MongoProcessFactory('mongodb://localhost:27017'));
    }

    protected function expectedKey(): string
    {
        return 'mongo';
    }

    protected function expectedEngine(): DatabaseEngine
    {
        return DatabaseEngine::Mongo;
    }

    protected function unsupportedKind(): BackupKind
    {
        // Mongo has no PITR base backups — must reject, not degrade.
        return BackupKind::PhysicalBase;
    }

    public function test_pitr_is_honestly_unsupported(): void
    {
        $this->assertHonestlyUnsupported(
            $this->createDriver()->capabilities(),
            \Vortos\Backup\Port\Capability\BackupTargetCapability::Pitr,
        );
    }
}
