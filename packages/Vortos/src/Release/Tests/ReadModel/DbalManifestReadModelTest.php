<?php

declare(strict_types=1);

namespace Vortos\Release\Tests\ReadModel;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Vortos\Release\Manifest\Arch;
use Vortos\Release\Manifest\BuildManifest;
use Vortos\Release\Manifest\Provenance;
use Vortos\Release\ReadModel\DbalManifestReadModel;
use Vortos\Release\ReadModel\DbalManifestRepository;
use Vortos\Release\Schema\SchemaFingerprint;

final class DbalManifestReadModelTest extends TestCase
{
    private Connection $connection;
    private DbalManifestRepository $repo;
    private DbalManifestReadModel $readModel;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createTables($this->connection);
        $this->repo = new DbalManifestRepository($this->connection, 'release_build_manifests', 'release_env_schema_state');
        $this->readModel = new DbalManifestReadModel($this->connection, 'release_build_manifests', 'release_env_schema_state');
    }

    // ── manifest(buildId) ──

    public function test_manifest_returns_null_for_nonexistent(): void
    {
        $this->assertNull($this->readModel->manifest('nope'));
    }

    public function test_manifest_returns_stored_manifest(): void
    {
        $original = $this->makeManifest('b1');
        $this->repo->record($original);

        $found = $this->readModel->manifest('b1');
        $this->assertNotNull($found);
        $this->assertSame('b1', $found->buildId);
        $this->assertSame($original->gitSha, $found->gitSha);
        $this->assertSame($original->imageDigest, $found->imageDigest);
        $this->assertSame($original->targetArch, $found->targetArch);
        $this->assertSame($original->environment, $found->environment);
        $this->assertTrue($original->schemaFingerprint->equals($found->schemaFingerprint));
    }

    public function test_manifest_with_provenance(): void
    {
        $this->repo->record($this->makeManifest('b2', provenance: new Provenance('ci', 'sha256:' . str_repeat('b', 64), 'sig')));

        $found = $this->readModel->manifest('b2');
        $this->assertNotNull($found->provenance);
        $this->assertSame('ci', $found->provenance->builderId);
        $this->assertSame('sig', $found->provenance->signature);
    }

    public function test_manifest_without_provenance(): void
    {
        $this->repo->record($this->makeManifest('b3'));

        $found = $this->readModel->manifest('b3');
        $this->assertNull($found->provenance);
    }

    // ── latestForEnvironment ──

    public function test_latest_for_environment_returns_null_when_empty(): void
    {
        $this->assertNull($this->readModel->latestForEnvironment('production'));
    }

    public function test_latest_for_environment_returns_most_recent(): void
    {
        $this->repo->record($this->makeManifest('old', createdAt: '2026-06-22T10:00:00+00:00'));
        $this->repo->record($this->makeManifest('new', createdAt: '2026-06-23T10:00:00+00:00'));

        $latest = $this->readModel->latestForEnvironment('production');
        $this->assertSame('new', $latest->buildId);
    }

    public function test_latest_for_environment_scoped_by_env(): void
    {
        $this->repo->record($this->makeManifest('prod1', env: 'production'));
        $this->repo->record($this->makeManifest('stg1', env: 'staging'));

        $latest = $this->readModel->latestForEnvironment('staging');
        $this->assertSame('stg1', $latest->buildId);
    }

    // ── currentApplied(env) ──

    public function test_current_applied_returns_empty_for_unknown_env(): void
    {
        $fp = $this->readModel->currentApplied('nonexistent');
        $this->assertTrue($fp->isEmpty());
    }

    public function test_current_applied_returns_latest_snapshot(): void
    {
        $fp1 = new SchemaFingerprint(['m1']);
        $this->repo->record($this->makeManifest('b1', fingerprint: $fp1));

        $fp2 = new SchemaFingerprint(['m1', 'm2']);
        $this->repo->record($this->makeManifest('b2', fingerprint: $fp2));

        $applied = $this->readModel->currentApplied('production');
        $this->assertTrue($fp2->equals($applied));
    }

    public function test_current_applied_independent_per_env(): void
    {
        $this->repo->record($this->makeManifest('b1', env: 'production', fingerprint: new SchemaFingerprint(['m1', 'm2'])));
        $this->repo->record($this->makeManifest('b2', env: 'staging', fingerprint: new SchemaFingerprint(['m1'])));

        $prod = $this->readModel->currentApplied('production');
        $stg = $this->readModel->currentApplied('staging');

        $this->assertSame(2, $prod->count());
        $this->assertSame(1, $stg->count());
    }

    // ── knownMigrationSet ──

    public function test_known_migration_set_empty(): void
    {
        $set = $this->readModel->knownMigrationSet();
        $this->assertSame([], $set->ids);
    }

    public function test_known_migration_set_unions_all_manifests(): void
    {
        $this->repo->record($this->makeManifest('b1', fingerprint: new SchemaFingerprint(['m1', 'm2'])));
        $this->repo->record($this->makeManifest('b2', fingerprint: new SchemaFingerprint(['m2', 'm3'])));
        $this->repo->record($this->makeManifest('b3', fingerprint: new SchemaFingerprint(['m1', 'm3', 'm4']), env: 'staging'));

        $set = $this->readModel->knownMigrationSet();
        $this->assertSame(['m1', 'm2', 'm3', 'm4'], $set->ids);
    }

    public function test_known_migration_set_deduplicates(): void
    {
        $this->repo->record($this->makeManifest('b1', fingerprint: new SchemaFingerprint(['m1'])));
        $this->repo->record($this->makeManifest('b2', fingerprint: new SchemaFingerprint(['m1'])));

        $set = $this->readModel->knownMigrationSet();
        $this->assertSame(['m1'], $set->ids);
    }

    // ── Helpers ──

    private function makeManifest(
        string $buildId,
        ?SchemaFingerprint $fingerprint = null,
        ?Provenance $provenance = null,
        string $env = 'production',
        string $createdAt = '2026-06-23T12:00:00+00:00',
    ): BuildManifest {
        return new BuildManifest(
            buildId: $buildId,
            gitSha: 'abc1234',
            imageDigest: 'sha256:' . str_repeat('a', 64),
            targetArch: Arch::Arm64,
            environment: $env,
            schemaFingerprint: $fingerprint ?? new SchemaFingerprint(['m1']),
            createdAt: new \DateTimeImmutable($createdAt),
            provenance: $provenance,
        );
    }

    private function createTables(Connection $conn): void
    {
        $schema = new Schema();

        $manifests = $schema->createTable('release_build_manifests');
        $manifests->addColumn('build_id', 'string', ['length' => 36]);
        $manifests->addColumn('git_sha', 'string', ['length' => 40]);
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
