<?php

declare(strict_types=1);

namespace Vortos\Migration\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

final class MigrationLockSafetyEnforcer
{
    public function __construct(
        private readonly Connection $connection,
        private readonly int $lockTimeoutMs = 3000,
        private readonly int $statementTimeoutMs = 0,
    ) {}

    public function enforce(): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return;
        }

        if ($this->lockTimeoutMs > 0) {
            $this->connection->executeStatement(
                sprintf('SET lock_timeout = %d', $this->lockTimeoutMs),
            );
        }

        if ($this->statementTimeoutMs > 0) {
            $this->connection->executeStatement(
                sprintf('SET statement_timeout = %d', $this->statementTimeoutMs),
            );
        }
    }

    public function reset(): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return;
        }

        $this->connection->executeStatement('SET lock_timeout = 0');
        $this->connection->executeStatement('SET statement_timeout = 0');
    }

    public function lockTimeoutMs(): int
    {
        return $this->lockTimeoutMs;
    }

    public function statementTimeoutMs(): int
    {
        return $this->statementTimeoutMs;
    }
}
