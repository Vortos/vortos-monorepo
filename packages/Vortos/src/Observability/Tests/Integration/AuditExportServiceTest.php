<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Audit\ActorIdentitySource;
use Vortos\Deploy\Audit\DeployAuditAggregate;
use Vortos\Observability\Audit\AuditExportService;
use Vortos\Observability\Audit\DbalDeployAuditViewRepository;
use Vortos\Observability\Audit\DeployAuditProjector;
use Vortos\Observability\Audit\DeployAuditQuery;
use Vortos\Observability\Audit\ExportFormat;

final class AuditExportServiceTest extends TestCase
{
    private const HMAC_KEY = 'export-test-key';

    private DbalDeployAuditViewRepository $repository;
    private AuditExportService $exporter;

    protected function setUp(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createTable($connection);
        $this->repository = new DbalDeployAuditViewRepository($connection, 'observability_deploy_audit_log');
        $projector = new DeployAuditProjector($this->repository, self::HMAC_KEY);
        $this->exporter = new AuditExportService($this->repository, self::HMAC_KEY);

        foreach (range(0, 2) as $i) {
            $projector->apply(DeployAuditAggregate::attempted(
                'prod', 'alice', ActorIdentitySource::Oidc, "build-{$i}", 'sha1', 'sha256:' . str_repeat('a', 64), 'fp1', null,
            )->pullDomainEvents()[0]);
        }
    }

    private function createTable(Connection $connection): void
    {
        $schema = new Schema();
        $table = $schema->createTable('observability_deploy_audit_log');
        $table->addColumn('entry_id', 'string', ['length' => 36]);
        $table->addColumn('sequence', 'integer');
        $table->addColumn('event_type', 'string', ['length' => 64]);
        $table->addColumn('actor_id', 'string', ['length' => 255]);
        $table->addColumn('actor_identity_source', 'string', ['length' => 32]);
        $table->addColumn('env', 'string', ['length' => 64]);
        $table->addColumn('build_id', 'string', ['length' => 64, 'notnull' => false]);
        $table->addColumn('git_sha', 'string', ['length' => 40, 'notnull' => false]);
        $table->addColumn('image_digest', 'string', ['length' => 71, 'notnull' => false]);
        $table->addColumn('schema_fingerprint_id', 'string', ['length' => 71, 'notnull' => false]);
        $table->addColumn('reason', 'text', ['notnull' => false]);
        $table->addColumn('occurred_at', 'string', ['length' => 32]);
        $table->addColumn('data', 'text');
        $table->addColumn('prev_hash', 'string', ['length' => 64]);
        $table->addColumn('content_hash', 'string', ['length' => 64]);
        $table->addColumn('signature', 'string', ['length' => 64]);
        $table->setPrimaryKey(['entry_id']);
        $table->addUniqueIndex(['env', 'sequence']);

        foreach ($schema->toSql($connection->getDatabasePlatform()) as $sql) {
            $connection->executeStatement($sql);
        }
    }

    public function test_export_ndjson_and_verify_round_trip(): void
    {
        $rows = [];
        $manifest = $this->exporter->export(new DeployAuditQuery(env: 'prod'), ExportFormat::Ndjson, static function (string $line) use (&$rows): void {
            $rows[] = $line;
        });

        self::assertSame(3, $manifest->rowCount);
        self::assertTrue($this->exporter->verify(new DeployAuditQuery(env: 'prod'), ExportFormat::Ndjson, $manifest));
    }

    public function test_verify_fails_when_manifest_is_tampered(): void
    {
        $manifest = $this->exporter->export(new DeployAuditQuery(env: 'prod'), ExportFormat::Ndjson, static function (): void {});

        $tampered = new \Vortos\Observability\Audit\SignedAuditManifest(
            $manifest->schemaVersion,
            $manifest->format,
            $manifest->rowCount + 1,
            $manifest->rangeFrom,
            $manifest->rangeTo,
            $manifest->generatedAt,
            $manifest->generatorIdentity,
            $manifest->contentHash,
            $manifest->signature,
        );

        self::assertFalse($this->exporter->verify(new DeployAuditQuery(env: 'prod'), ExportFormat::Ndjson, $tampered));
    }

    public function test_csv_export_includes_header(): void
    {
        $rows = [];
        $this->exporter->export(new DeployAuditQuery(env: 'prod'), ExportFormat::Csv, static function (string $line) use (&$rows): void {
            $rows[] = $line;
        });

        self::assertStringContainsString('entry_id', $rows[0]);
        self::assertCount(4, $rows); // header + 3 rows
    }
}
