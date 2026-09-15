<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Throwable;
use Vortos\Backup\Pitr\PgCheckpointerTimersReader;

/**
 * RC-10: the checkpointer timers read from a real PostgreSQL — the setting's base unit, the epoch conversion of
 * pg_postmaster_start_time() and pg_control_checkpoint(), and that a CHECKPOINT moves the checkpoint time past
 * the start. Skips cleanly when the write DB is unreachable.
 */
final class PgCheckpointerTimersReaderTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        try {
            $this->connection = DriverManager::getConnection([
                'driver' => 'pdo_pgsql',
                'host' => $_ENV['VORTOS_WRITE_DB_HOST'] ?? 'write_db',
                'port' => (int) ($_ENV['VORTOS_WRITE_DB_PORT'] ?? 5432),
                'user' => $_ENV['VORTOS_WRITE_DB_USER'] ?? 'postgres',
                'password' => $_ENV['VORTOS_WRITE_DB_PASSWORD'] ?? '12345',
                'dbname' => $_ENV['VORTOS_WRITE_DB_NAME'] ?? 'squaura',
            ]);
            $this->connection->executeQuery('SELECT 1');
        } catch (Throwable $e) {
            self::markTestSkipped('Postgres write DB not reachable: ' . $e->getMessage());
        }
    }

    public function test_it_reads_the_live_timers_in_their_base_units(): void
    {
        $timers = (new PgCheckpointerTimersReader($this->connection))->read();

        $expectedTimeout = (int) $this->connection->fetchOne("SELECT setting::int FROM pg_settings WHERE name = 'archive_timeout'");
        self::assertSame($expectedTimeout, $timers->archiveTimeoutSeconds);
        self::assertFalse($timers->inRecovery);
        self::assertLessThanOrEqual(new DateTimeImmutable(), $timers->postmasterStartedAt);
        self::assertSame(
            (int) $this->connection->fetchOne('SELECT floor(extract(epoch FROM pg_postmaster_start_time()))::bigint'),
            $timers->postmasterStartedAt->getTimestamp(),
        );
    }

    public function test_a_checkpoint_marks_the_checkpointer_awake_since_this_start(): void
    {
        $this->connection->executeStatement('CHECKPOINT');

        self::assertTrue((new PgCheckpointerTimersReader($this->connection))->read()->checkpointedSinceStart());
    }
}
