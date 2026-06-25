<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\BackupRequest;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Domain\Exception\DumpFailedException;
use Vortos\Backup\Driver\Mongo\MongoBackupTarget;
use Vortos\Backup\Driver\Mongo\MongoProcessFactory;
use Vortos\Backup\Driver\Postgres\PostgresBackupTarget;
use Vortos\Backup\Driver\Postgres\PostgresProcessFactory;

/**
 * Fail-closed: when the dump binary is missing, the target raises a clear, named
 * {@see DumpFailedException} rather than producing an empty/garbage stream.
 */
final class MissingBinaryTest extends TestCase
{
    private function binaryAbsent(string $binary): bool
    {
        $found = @shell_exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null');

        return $found === null || trim((string) $found) === '';
    }

    public function test_postgres_missing_binary_fails_closed_with_name(): void
    {
        if (!$this->binaryAbsent('pg_dump')) {
            $this->markTestSkipped('pg_dump is installed; missing-binary path not exercised here.');
        }

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $target = new PostgresBackupTarget(new PostgresProcessFactory($connection));

        $this->expectException(DumpFailedException::class);
        $this->expectExceptionMessageMatches('/pg_dump/');
        $target->dump(new BackupRequest(DatabaseEngine::Postgres, BackupKind::LogicalFull, 'test'));
    }

    public function test_mongo_missing_binary_fails_closed_with_name(): void
    {
        if (!$this->binaryAbsent('mongodump')) {
            $this->markTestSkipped('mongodump is installed; missing-binary path not exercised here.');
        }

        $target = new MongoBackupTarget(new MongoProcessFactory('mongodb://localhost:27017'));

        $this->expectException(DumpFailedException::class);
        $this->expectExceptionMessageMatches('/mongodump/');
        $target->dump(new BackupRequest(DatabaseEngine::Mongo, BackupKind::MongoArchive, 'test'));
    }
}
