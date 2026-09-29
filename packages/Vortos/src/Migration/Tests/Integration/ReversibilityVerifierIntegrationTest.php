<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use PHPUnit\Framework\TestCase;
use Vortos\Migration\Service\ReversibilityVerifier;
use Vortos\Migration\Service\TransactionAwareMigrationRunner;

/**
 * down-verify, through Doctrine, against a real PostgreSQL database.
 *
 * It used to extract migration SQL as text and run the strings, so a migration binding a parameter
 * failed at the placeholder, and `--count=N` ran the last N against an empty database without the
 * schema they alter. These run the project's way — Doctrine's migrator — and prove both work.
 *
 * DESTRUCTIVE to the tables it names. Runs only when VORTOS_DOWNVERIFY_IT_DSN names a throwaway
 * PostgreSQL database.
 */
final class ReversibilityVerifierIntegrationTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $dsn = (string) (getenv('VORTOS_DOWNVERIFY_IT_DSN') ?: '');
        if ($dsn === '') {
            self::markTestSkipped('VORTOS_DOWNVERIFY_IT_DSN is not set (a throwaway PostgreSQL database).');
        }

        $this->connection = DriverManager::getConnection(
            (new DsnParser(['postgres' => 'pdo_pgsql', 'postgresql' => 'pdo_pgsql', 'pgsql' => 'pdo_pgsql']))->parse($dsn),
        );
        $this->connection->executeStatement('DROP TABLE IF EXISTS rv_things, rv_versions');
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS rv_things, rv_versions');
            $this->connection->close();
        }
    }

    public function test_every_migration_round_trips_including_one_that_binds_a_parameter(): void
    {
        $result = $this->verifier()->verify(fn () => $this->factory('Reversible'), 0);

        self::assertTrue($result->ok, (string) $result->reason);
        self::assertSame(3, $result->verifiedCount);
    }

    public function test_a_window_is_verified_on_top_of_the_migrations_before_it(): void
    {
        $result = $this->verifier()->verify(fn () => $this->factory('Reversible'), 1);

        self::assertTrue($result->ok, (string) $result->reason);
        self::assertSame(['Vortos\Migration\Tests\Fixtures\Reversibility\Reversible\Version20260101000003'], $result->verified);
        // The prerequisites stay applied; the window came back up.
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM information_schema.columns WHERE table_name = 'rv_things' AND column_name = 'label'"));
    }

    public function test_a_declared_irreversible_migration_in_the_window_is_named(): void
    {
        $result = $this->verifier()->verify(fn () => $this->factory('Irreversible'), 0);

        self::assertFalse($result->ok);
        self::assertSame('down', $result->phase);
        self::assertStringEndsWith('Version20260101000001', (string) $result->version);
        self::assertStringContainsString('declared irreversible', (string) $result->reason);
    }

    private function verifier(): ReversibilityVerifier
    {
        return new ReversibilityVerifier(new TransactionAwareMigrationRunner());
    }

    private function factory(string $set): DependencyFactory
    {
        return DependencyFactory::fromConnection(
            new ConfigurationArray([
                'table_storage'    => ['table_name' => 'rv_versions'],
                'migrations_paths' => ['Vortos\Migration\Tests\Fixtures\Reversibility\\' . $set => dirname(__DIR__) . '/Fixtures/Reversibility/' . $set],
            ]),
            new ExistingConnection($this->connection),
        );
    }
}
