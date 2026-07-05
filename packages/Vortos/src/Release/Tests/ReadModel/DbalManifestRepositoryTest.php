<?php

declare(strict_types=1);

namespace Vortos\Release\Tests\ReadModel;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Vortos\Release\Manifest\Arch;
use Vortos\Release\Manifest\BuildManifest;
use Vortos\Release\Manifest\ManifestAlreadyExistsException;
use Vortos\Release\Manifest\ManifestSchemaMissingException;
use Vortos\Release\Manifest\Provenance;
use Vortos\Release\ReadModel\DbalManifestRepository;
use Vortos\Release\Schema\SchemaFingerprint;

final class DbalManifestRepositoryTest extends TestCase
{
    private Connection $connection;
    private DbalManifestRepository $repo;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createTables($this->connection);
        $this->repo = new DbalManifestRepository($this->connection, 'release_build_manifests', 'release_env_schema_state');
    }

    // ── Record ──

    public function test_record_inserts_manifest(): void
    {
        $manifest = $this->makeManifest('build-001');
        $this->repo->record($manifest);

        $row = $this->connection->fetchAssociative('SELECT * FROM release_build_manifests WHERE build_id = ?', ['build-001']);
        $this->assertIsArray($row);
        $this->assertSame('build-001', $row['build_id']);
        $this->assertSame('abc1234', $row['git_sha']);
        $this->assertSame('sha256:' . str_repeat('a', 64), $row['image_digest']);
        $this->assertSame('linux/arm64', $row['target_arch']);
        $this->assertSame('production', $row['environment']);
    }

    public function test_record_stores_schema_hash_and_migration_ids(): void
    {
        $fp = new SchemaFingerprint(['m1', 'm2']);
        $manifest = $this->makeManifest('build-002', fingerprint: $fp);
        $this->repo->record($manifest);

        $row = $this->connection->fetchAssociative('SELECT schema_hash, migration_ids FROM release_build_manifests WHERE build_id = ?', ['build-002']);
        $this->assertSame($fp->hash, $row['schema_hash']);
        $this->assertSame(['m1', 'm2'], json_decode($row['migration_ids'], true));
    }

    public function test_record_stores_provenance(): void
    {
        $manifest = $this->makeManifest('build-003', provenance: new Provenance('ci', 'sha256:' . str_repeat('b', 64)));
        $this->repo->record($manifest);

        $row = $this->connection->fetchAssociative('SELECT provenance FROM release_build_manifests WHERE build_id = ?', ['build-003']);
        $decoded = json_decode($row['provenance'], true);
        $this->assertSame('ci', $decoded['builder_id']);
    }

    public function test_record_stores_null_provenance(): void
    {
        $manifest = $this->makeManifest('build-004');
        $this->repo->record($manifest);

        $row = $this->connection->fetchAssociative('SELECT provenance FROM release_build_manifests WHERE build_id = ?', ['build-004']);
        $this->assertNull($row['provenance']);
    }

    public function test_record_duplicate_throws(): void
    {
        $manifest = $this->makeManifest('build-dup');
        $this->repo->record($manifest);

        $this->expectException(ManifestAlreadyExistsException::class);
        $this->expectExceptionMessage('build-dup');
        $this->repo->record($manifest);
    }

    public function test_record_against_missing_schema_fails_closed_with_guidance(): void
    {
        // G9 defense-in-depth: a fresh DB where migrations have not run. Instead of a raw SQLSTATE
        // 42P01 / "no such table", record() must surface actionable guidance to run vortos:migrate.
        $freshDb = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $repo = new DbalManifestRepository($freshDb, 'release_build_manifests', 'release_env_schema_state');

        $this->expectException(ManifestSchemaMissingException::class);
        $this->expectExceptionMessage('vortos:migrate');
        $repo->record($this->makeManifest('build-fresh'));
    }

    // ── Env state upsert ──

    public function test_record_upserts_env_state(): void
    {
        $fp1 = new SchemaFingerprint(['m1']);
        $this->repo->record($this->makeManifest('b1', fingerprint: $fp1));

        $state = $this->connection->fetchAssociative('SELECT * FROM release_env_schema_state WHERE environment = ?', ['production']);
        $this->assertSame($fp1->hash, $state['schema_hash']);

        $fp2 = new SchemaFingerprint(['m1', 'm2']);
        $this->repo->record($this->makeManifest('b2', fingerprint: $fp2));

        $state = $this->connection->fetchAssociative('SELECT * FROM release_env_schema_state WHERE environment = ?', ['production']);
        $this->assertSame($fp2->hash, $state['schema_hash']);
    }

    public function test_record_different_environments_create_separate_state(): void
    {
        $this->repo->record($this->makeManifest('b1', env: 'staging'));
        $this->repo->record($this->makeManifest('b2', env: 'production'));

        $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM release_env_schema_state');
        $this->assertSame(2, $count);
    }

    // ── Helpers ──

    private function makeManifest(
        string $buildId,
        ?SchemaFingerprint $fingerprint = null,
        ?Provenance $provenance = null,
        string $env = 'production',
    ): BuildManifest {
        return new BuildManifest(
            buildId: $buildId,
            gitSha: 'abc1234',
            imageRepository: 'ghcr.io/acme/app',
            imageDigest: 'sha256:' . str_repeat('a', 64),
            targetArch: Arch::Arm64,
            environment: $env,
            schemaFingerprint: $fingerprint ?? new SchemaFingerprint(['m1']),
            createdAt: new \DateTimeImmutable('2026-06-23T12:00:00+00:00'),
            provenance: $provenance,
        );
    }

    private function createTables(Connection $conn): void
    {
        $schema = new Schema();

        $manifests = $schema->createTable('release_build_manifests');
        $manifests->addColumn('build_id', 'string', ['length' => 36]);
        $manifests->addColumn('git_sha', 'string', ['length' => 40]);
        $manifests->addColumn('image_repository', 'string', ['length' => 255]);
        $manifests->addColumn('image_digest', 'string', ['length' => 71]);
        $manifests->addColumn('target_arch', 'string', ['length' => 20]);
        $manifests->addColumn('environment', 'string', ['length' => 64]);
        $manifests->addColumn('schema_hash', 'string', ['length' => 71]);
        $manifests->addColumn('migration_ids', 'text');
        $manifests->addColumn('provenance', 'text', ['notnull' => false]);
        $manifests->addColumn('created_at', 'string', ['length' => 30]);
        $manifests->setPrimaryKey(['build_id']);
        $manifests->addIndex(['environment', 'created_at'], 'idx_release_env_created');
        $manifests->addIndex(['schema_hash'], 'idx_release_schema_hash');

        $envState = $schema->createTable('release_env_schema_state');
        $envState->addColumn('environment', 'string', ['length' => 64]);
        $envState->addColumn('schema_hash', 'string', ['length' => 71]);
        $envState->addColumn('migration_ids', 'text');
        $envState->addColumn('updated_at', 'string', ['length' => 30]);
        $envState->setPrimaryKey(['environment']);

        foreach ($schema->toSql($conn->getDatabasePlatform()) as $sql) {
            $conn->executeStatement($sql);
        }
    }
}
