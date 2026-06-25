<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use PHPUnit\Framework\TestCase;
use Vortos\Migration\Service\MigrationLockSafetyEnforcer;

final class MigrationLockSafetyEnforcerTest extends TestCase
{
    public function test_enforce_on_postgres_sets_lock_timeout(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());

        $statements = [];
        $connection->method('executeStatement')
            ->willReturnCallback(function (string $sql) use (&$statements) {
                $statements[] = $sql;
                return 0;
            });

        $enforcer = new MigrationLockSafetyEnforcer($connection, lockTimeoutMs: 5000, statementTimeoutMs: 10000);
        $enforcer->enforce();

        self::assertContains('SET lock_timeout = 5000', $statements);
        self::assertContains('SET statement_timeout = 10000', $statements);
    }

    public function test_enforce_on_non_postgres_is_noop(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($this->createMock(AbstractPlatform::class));

        $connection->expects($this->never())->method('executeStatement');

        $enforcer = new MigrationLockSafetyEnforcer($connection);
        $enforcer->enforce();
    }

    public function test_reset_on_postgres(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());

        $statements = [];
        $connection->method('executeStatement')
            ->willReturnCallback(function (string $sql) use (&$statements) {
                $statements[] = $sql;
                return 0;
            });

        $enforcer = new MigrationLockSafetyEnforcer($connection);
        $enforcer->reset();

        self::assertContains('SET lock_timeout = 0', $statements);
        self::assertContains('SET statement_timeout = 0', $statements);
    }

    public function test_reset_on_non_postgres_is_noop(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($this->createMock(AbstractPlatform::class));

        $connection->expects($this->never())->method('executeStatement');

        $enforcer = new MigrationLockSafetyEnforcer($connection);
        $enforcer->reset();
    }

    public function test_default_lock_timeout_is_3000(): void
    {
        $connection = $this->createMock(Connection::class);
        $enforcer = new MigrationLockSafetyEnforcer($connection);

        self::assertSame(3000, $enforcer->lockTimeoutMs());
    }

    public function test_default_statement_timeout_is_0(): void
    {
        $connection = $this->createMock(Connection::class);
        $enforcer = new MigrationLockSafetyEnforcer($connection);

        self::assertSame(0, $enforcer->statementTimeoutMs());
    }

    public function test_zero_lock_timeout_skips_set(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());

        $connection->expects($this->never())->method('executeStatement');

        $enforcer = new MigrationLockSafetyEnforcer($connection, lockTimeoutMs: 0, statementTimeoutMs: 0);
        $enforcer->enforce();
    }
}
