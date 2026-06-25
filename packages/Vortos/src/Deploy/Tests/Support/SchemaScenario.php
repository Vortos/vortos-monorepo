<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Throwable;

/**
 * A reusable expand/contract schema fixture against a real Postgres database, for
 * proving the dual-write/expand-contract invariant that {@see
 * \Vortos\Deploy\Plan\PhaseGate} and {@see \Vortos\Deploy\Plan\DeployPreflightStateBuilder}
 * exist to protect: while a migration is in the `expand` phase, both the
 * not-yet-deployed ("old") and newly-deployed ("new") application code must be able to
 * read/write the same table; once a migration reaches `contract`, old code referencing
 * the dropped column must break (which is exactly the breakage the soak gate exists to
 * prevent from happening while old code might still be live).
 *
 * Same `markTestSkipped()`-on-unreachable convention as
 * {@see \Vortos\Backup\Tests\Integration\PostgresInfraTest} — skips cleanly without
 * Docker, runs for real against the Postgres in the Docker Compose stack.
 *
 * Shared across the Block 8 (contract soak), Block 10 (worker drain), and Block 21
 * (migration safety) test suites rather than rebuilt per package.
 */
trait SchemaScenario
{
    protected function connectToWriteDbOrSkip(): Connection
    {
        try {
            $connection = DriverManager::getConnection([
                'driver' => 'pdo_pgsql',
                'host' => $_ENV['VORTOS_WRITE_DB_HOST'] ?? 'write_db',
                'port' => (int) ($_ENV['VORTOS_WRITE_DB_PORT'] ?? 5432),
                'user' => $_ENV['VORTOS_WRITE_DB_USER'] ?? 'postgres',
                'password' => $_ENV['VORTOS_WRITE_DB_PASSWORD'] ?? '12345',
                'dbname' => $_ENV['VORTOS_WRITE_DB_NAME'] ?? 'squaura',
            ]);
            $connection->executeQuery('SELECT 1');

            return $connection;
        } catch (Throwable $e) {
            $this->markTestSkipped('Postgres write DB not reachable: ' . $e->getMessage());
        }
    }

    /**
     * Creates a table with only the legacy column ("old" code's view of the schema).
     */
    protected function createLegacyTable(Connection $conn, string $table): void
    {
        $conn->executeStatement(<<<SQL
            CREATE TABLE {$table} (
                id SERIAL PRIMARY KEY,
                name_old TEXT NOT NULL
            )
            SQL);
    }

    /**
     * The "expand" half of a rename-via-expand-contract migration: add the new column
     * additively, and relax the legacy column's NOT NULL constraint — a real expand
     * step must do both, otherwise new code that only writes `name_new` would violate
     * the old column's constraint, which is itself backward-incompatible.
     */
    protected function applyExpandPhase(Connection $conn, string $table): void
    {
        $conn->executeStatement("ALTER TABLE {$table} ADD COLUMN name_new TEXT");
        $conn->executeStatement("ALTER TABLE {$table} ALTER COLUMN name_old DROP NOT NULL");
    }

    /**
     * The "contract" half: drop the legacy column. Destructive — anything still reading
     * or writing `name_old` now fails. This is the half the soak gate exists to delay
     * until no old code can possibly still be running.
     */
    protected function applyContractPhase(Connection $conn, string $table): void
    {
        $conn->executeStatement("ALTER TABLE {$table} DROP COLUMN name_old");
    }

    /** Simulates the not-yet-deployed ("old") application code path. */
    protected function oldCodeWrite(Connection $conn, string $table, string $value): void
    {
        $conn->executeStatement("INSERT INTO {$table} (name_old) VALUES (?)", [$value]);
    }

    /** Simulates the newly-deployed ("new") application code path. */
    protected function newCodeWrite(Connection $conn, string $table, string $value): void
    {
        $conn->executeStatement("INSERT INTO {$table} (name_new) VALUES (?)", [$value]);
    }

    protected function dropScenarioTable(Connection $conn, string $table): void
    {
        $conn->executeStatement("DROP TABLE IF EXISTS {$table}");
    }

    protected function scenarioTableName(): string
    {
        return 'vortos_deploy_dualwrite_scenario_' . bin2hex(random_bytes(4));
    }
}
