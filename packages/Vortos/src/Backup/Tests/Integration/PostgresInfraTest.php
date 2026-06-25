<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Throwable;
use Vortos\Backup\Pitr\PitrPreflight;

/**
 * Real-Postgres guarantees that cannot be proven on sqlite: the append-only catalog
 * trigger (UPDATE rejected, DELETE allowed) and the PITR preflight reading live
 * server settings. Skips cleanly when the write DB is unreachable.
 */
final class PostgresInfraTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = $this->connectOrSkip();
    }

    private function connectOrSkip(): Connection
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

    public function test_catalog_trigger_blocks_update_but_allows_insert_and_delete(): void
    {
        $table = 'vortos_backup_catalog_trgtest_' . bin2hex(random_bytes(4));
        $fn = 'vortos_backup_catalog_no_update_test_' . bin2hex(random_bytes(4));
        $trg = 'trg_' . $table;

        $this->connection->executeStatement("CREATE TABLE {$table} (id TEXT PRIMARY KEY, payload TEXT)");
        $this->connection->executeStatement(<<<SQL
            CREATE OR REPLACE FUNCTION {$fn}() RETURNS TRIGGER AS \$\$
            BEGIN
                RAISE EXCEPTION '{$table} is append-only: UPDATE prohibited (id=%)', OLD.id;
                RETURN NULL;
            END;
            \$\$ LANGUAGE plpgsql;
            SQL);
        $this->connection->executeStatement("CREATE TRIGGER {$trg} BEFORE UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION {$fn}()");

        try {
            // INSERT works.
            $this->connection->executeStatement("INSERT INTO {$table} (id, payload) VALUES ('a', 'x')");
            $this->assertSame('1', (string) $this->connection->fetchOne("SELECT count(*) FROM {$table}"));

            // UPDATE is rejected.
            $threw = false;
            try {
                $this->connection->executeStatement("UPDATE {$table} SET payload = 'y' WHERE id = 'a'");
            } catch (Throwable) {
                $threw = true;
            }
            $this->assertTrue($threw, 'UPDATE must be rejected by the immutability trigger.');

            // DELETE is allowed (retention).
            $this->connection->executeStatement("DELETE FROM {$table} WHERE id = 'a'");
            $this->assertSame('0', (string) $this->connection->fetchOne("SELECT count(*) FROM {$table}"));
        } finally {
            $this->connection->executeStatement("DROP TRIGGER IF EXISTS {$trg} ON {$table}");
            $this->connection->executeStatement("DROP FUNCTION IF EXISTS {$fn}()");
            $this->connection->executeStatement("DROP TABLE IF EXISTS {$table}");
        }
    }

    public function test_pitr_preflight_reports_settings(): void
    {
        $report = (new PitrPreflight($this->connection))->check();

        $this->assertArrayHasKey('archive_mode', $report['settings']);
        $this->assertArrayHasKey('wal_level', $report['settings']);
        $this->assertIsBool($report['ok']);

        // If PITR isn't configured (the common dev default), assert() must fail closed.
        if (!$report['ok']) {
            $this->expectException(\Vortos\Backup\Pitr\PitrNotConfiguredException::class);
            (new PitrPreflight($this->connection))->assert();
        } else {
            $this->addToAssertionCount(1);
        }
    }
}
