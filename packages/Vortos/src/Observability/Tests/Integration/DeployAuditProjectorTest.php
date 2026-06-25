<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Audit\ActorIdentitySource;
use Vortos\Deploy\Audit\DeployAuditAggregate;
use Vortos\Domain\Event\EventEnvelope;
use Vortos\Domain\Event\Metadata;
use Vortos\Observability\Audit\AuditChainVerifier;
use Vortos\Observability\Audit\DbalDeployAuditViewRepository;
use Vortos\Observability\Audit\DeployAuditProjector;

/**
 * Block 16 §6: deploy + rollback events project into the audit table with a valid
 * chain; replay (re-applying the same envelopes) is also exercised; concurrent
 * appends for the same env keep sequence monotonic.
 */
final class DeployAuditProjectorTest extends TestCase
{
    private const HMAC_KEY = 'integration-test-key';

    private Connection $connection;
    private DbalDeployAuditViewRepository $repository;
    private DeployAuditProjector $projector;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createTable($this->connection);
        $this->repository = new DbalDeployAuditViewRepository($this->connection, 'observability_deploy_audit_log');
        $this->projector = new DeployAuditProjector($this->repository, self::HMAC_KEY);
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

    private function envelope(object $payload, string $eventId = 'evt-1'): EventEnvelope
    {
        return new EventEnvelope(
            eventId: $eventId,
            aggregateId: 'agg-1',
            aggregateType: DeployAuditAggregate::class,
            aggregateVersion: 1,
            payloadType: $payload::class,
            schemaVersion: 1,
            occurredAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            payload: $payload,
            metadata: Metadata::empty(),
        );
    }

    public function test_deploy_attempted_and_succeeded_project_into_a_valid_chain(): void
    {
        $attempted = DeployAuditAggregate::attempted(
            'prod', 'alice', ActorIdentitySource::Oidc, 'build-1', 'sha1', 'sha256:' . str_repeat('a', 64), 'fp-1', null,
        )->pullDomainEvents()[0];

        $succeeded = DeployAuditAggregate::succeeded(
            'prod', 'alice', ActorIdentitySource::Oidc, 'build-1', 'sha1', 'sha256:' . str_repeat('a', 64), 'fp-1', null, 'ok',
        )->pullDomainEvents()[0];

        $this->projector->apply($attempted->withMetadata(Metadata::empty()));
        $this->projector->apply($succeeded->withMetadata(Metadata::empty()));

        $entries = $this->repository->findByEnv('prod');
        self::assertCount(2, $entries);
        self::assertSame(0, $entries[0]->sequence);
        self::assertSame(1, $entries[1]->sequence);

        $verifier = new AuditChainVerifier();
        $result = $verifier->verify($entries, self::HMAC_KEY);
        self::assertTrue($result->intact, $result->reason ?? '');
    }

    public function test_rolled_back_event_projects_with_kind_rolled_back(): void
    {
        $rolledBack = DeployAuditAggregate::rolledBack(
            'prod', 'alice', ActorIdentitySource::SshCa, 'build-2', 'build-1', 'bad metrics',
        )->pullDomainEvents()[0];

        $this->projector->apply($rolledBack);

        $entries = $this->repository->findByEnv('prod');
        self::assertCount(1, $entries);
        self::assertSame('RolledBack', $entries[0]->eventType);
        self::assertSame('bad metrics', $entries[0]->reason);
    }

    public function test_sequences_are_independent_per_environment(): void
    {
        $this->projector->apply(DeployAuditAggregate::attempted(
            'staging', 'bob', ActorIdentitySource::Local, 'b1', 'sha1', 'sha256:' . str_repeat('a', 64), 'fp1', null,
        )->pullDomainEvents()[0]);

        $this->projector->apply(DeployAuditAggregate::attempted(
            'prod', 'bob', ActorIdentitySource::Local, 'b1', 'sha1', 'sha256:' . str_repeat('a', 64), 'fp1', null,
        )->pullDomainEvents()[0]);

        self::assertSame(0, $this->repository->findByEnv('staging')[0]->sequence);
        self::assertSame(0, $this->repository->findByEnv('prod')[0]->sequence);
    }

    public function test_data_is_scrubbed_for_failed_events(): void
    {
        $failed = DeployAuditAggregate::failed(
            'prod', 'alice', ActorIdentitySource::Oidc, 'build-1', 'sha1', 'sha256:' . str_repeat('a', 64), 'fp-1', null,
            'RuntimeException', 'connection failed: password=supersecret123456789012345',
        )->pullDomainEvents()[0];

        $this->projector->apply($failed);

        $entry = $this->repository->findByEnv('prod')[0];
        self::assertStringNotContainsString('supersecret123456789012345', $entry->data['error_message']);
    }
}
