<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Conformance;

use Doctrine\DBAL\DriverManager;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Driver\Postgres\PostgresBackupTarget;
use Vortos\Backup\Driver\Postgres\PostgresProcessFactory;
use Vortos\Backup\Testing\BackupTargetConformanceTestCase;
use Vortos\OpsKit\Driver\DriverInterface;

final class PostgresBackupTargetConformanceTest extends BackupTargetConformanceTestCase
{
    protected function createDriver(): DriverInterface
    {
        // Capability/engine/unsupported-kind assertions never touch the DB; a sqlite
        // connection is a harmless stand-in for constructing the factory.
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        return new PostgresBackupTarget(new PostgresProcessFactory($connection));
    }

    protected function expectedKey(): string
    {
        return 'postgres';
    }

    protected function expectedEngine(): DatabaseEngine
    {
        return DatabaseEngine::Postgres;
    }

    protected function unsupportedKind(): BackupKind
    {
        // Postgres cannot produce a Mongo archive — must reject, not degrade.
        return BackupKind::MongoArchive;
    }
}
