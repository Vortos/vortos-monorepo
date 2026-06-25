<?php

declare(strict_types=1);

namespace Vortos\Release\Tests\ReadModel;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Vortos\Release\Manifest\Arch;
use Vortos\Release\Manifest\BuildManifest;
use Vortos\Release\ReadModel\DbalManifestRepository;
use Vortos\Release\Schema\SchemaFingerprint;

/**
 * Tests the Postgres immutability trigger.
 *
 * Only runs when the RELEASE_POSTGRES_DSN env var is set (Docker integration).
 * Skipped on SQLite (no trigger support needed for the core unit tests).
 */
final class DbalManifestImmutabilityTest extends TestCase
{
    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $dsn = $_ENV['RELEASE_POSTGRES_DSN'] ?? getenv('RELEASE_POSTGRES_DSN') ?: null;

        if ($dsn === null || $dsn === false) {
            $this->markTestSkipped('RELEASE_POSTGRES_DSN not set — Postgres immutability trigger tests require a Postgres connection.');
        }

        $parsed = parse_url($dsn);
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => $parsed['host'] ?? 'localhost',
            'port' => $parsed['port'] ?? 5432,
            'user' => $parsed['user'] ?? 'postgres',
            'password' => $parsed['pass'] ?? '',
            'dbname' => ltrim($parsed['path'] ?? '/squaura', '/'),
        ]);

        $this->connection->executeStatement('
            CREATE TABLE IF NOT EXISTS vortos_release_build_manifests (
                build_id       VARCHAR(36)  PRIMARY KEY,
                git_sha        VARCHAR(40)  NOT NULL,
                image_digest   VARCHAR(71)  NOT NULL,
                target_arch    VARCHAR(20)  NOT NULL,
                environment    VARCHAR(64)  NOT NULL,
                schema_hash    VARCHAR(71)  NOT NULL,
                migration_ids  TEXT         NOT NULL,
                provenance     TEXT,
                created_at     TIMESTAMP    NOT NULL
            )
        ');

        $this->connection->executeStatement('
            CREATE TABLE IF NOT EXISTS vortos_release_env_schema_state (
                environment    VARCHAR(64)  PRIMARY KEY,
                schema_hash    VARCHAR(71)  NOT NULL,
                migration_ids  TEXT         NOT NULL,
                updated_at     TIMESTAMP    NOT NULL
            )
        ');

        $this->connection->executeStatement('
            CREATE OR REPLACE FUNCTION vortos_release_manifests_immutable()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION \'release_build_manifests is append-only: UPDATE and DELETE are prohibited (build_id=%)\', OLD.build_id;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql
        ');

        $this->connection->executeStatement('DROP TRIGGER IF EXISTS trg_release_manifests_immutable ON vortos_release_build_manifests');
        $this->connection->executeStatement('
            CREATE TRIGGER trg_release_manifests_immutable
                BEFORE UPDATE OR DELETE ON vortos_release_build_manifests
                FOR EACH ROW EXECUTE FUNCTION vortos_release_manifests_immutable()
        ');

        $this->connection->executeStatement('DELETE FROM vortos_release_env_schema_state');
        $this->connection->executeStatement('TRUNCATE vortos_release_build_manifests');
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->connection->executeStatement('DROP TRIGGER IF EXISTS trg_release_manifests_immutable ON vortos_release_build_manifests');
            $this->connection->executeStatement('DROP TABLE IF EXISTS vortos_release_env_schema_state');
            $this->connection->executeStatement('DROP TABLE IF EXISTS vortos_release_build_manifests');
            $this->connection->executeStatement('DROP FUNCTION IF EXISTS vortos_release_manifests_immutable()');
            $this->connection->close();
        }
    }

    public function test_update_is_rejected_by_trigger(): void
    {
        $repo = new DbalManifestRepository($this->connection, 'vortos_release_build_manifests', 'vortos_release_env_schema_state');
        $repo->record($this->makeManifest('immutable-001'));

        $this->expectException(\Doctrine\DBAL\Exception::class);
        $this->expectExceptionMessage('append-only');

        $this->connection->executeStatement(
            "UPDATE vortos_release_build_manifests SET git_sha = 'deadbeef' WHERE build_id = 'immutable-001'"
        );
    }

    public function test_delete_is_rejected_by_trigger(): void
    {
        $repo = new DbalManifestRepository($this->connection, 'vortos_release_build_manifests', 'vortos_release_env_schema_state');
        $repo->record($this->makeManifest('immutable-002'));

        $this->expectException(\Doctrine\DBAL\Exception::class);
        $this->expectExceptionMessage('append-only');

        $this->connection->executeStatement(
            "DELETE FROM vortos_release_build_manifests WHERE build_id = 'immutable-002'"
        );
    }

    public function test_insert_succeeds(): void
    {
        $repo = new DbalManifestRepository($this->connection, 'vortos_release_build_manifests', 'vortos_release_env_schema_state');
        $repo->record($this->makeManifest('insert-ok'));

        $row = $this->connection->fetchAssociative(
            "SELECT build_id FROM vortos_release_build_manifests WHERE build_id = 'insert-ok'"
        );
        $this->assertSame('insert-ok', $row['build_id']);
    }

    private function makeManifest(string $buildId): BuildManifest
    {
        return new BuildManifest(
            buildId: $buildId,
            gitSha: 'abc1234',
            imageDigest: 'sha256:' . str_repeat('a', 64),
            targetArch: Arch::Arm64,
            environment: 'production',
            schemaFingerprint: new SchemaFingerprint(['m1']),
            createdAt: new \DateTimeImmutable('2026-06-23T12:00:00+00:00'),
        );
    }
}
