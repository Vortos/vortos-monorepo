<?php

declare(strict_types=1);

namespace Vortos\Observability\Audit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

/**
 * Default (DBAL) append-only store for the deploy audit ledger. Single-writer per
 * env is enforced with a Postgres advisory transaction lock keyed by `env` —
 * cheaper than `SELECT ... FOR UPDATE` on a row that may not exist yet for a brand
 * new environment, and released automatically at transaction end. On non-Postgres
 * platforms (e.g. the SQLite in-memory connection used by repository-level tests)
 * the advisory lock is a no-op — DBAL's own transaction already serializes writers
 * on that single connection.
 */
final class DbalDeployAuditViewRepository implements DeployAuditViewRepositoryInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
    ) {
    }

    public function appendNext(string $env, callable $builder): AuditEntry
    {
        return $this->connection->transactional(function (Connection $conn) use ($env, $builder): AuditEntry {
            if ($conn->getDatabasePlatform() instanceof PostgreSQLPlatform) {
                $conn->executeStatement('SELECT pg_advisory_xact_lock(hashtext(:env))', ['env' => $env]);
            }

            $tail = $conn->fetchAssociative(
                sprintf('SELECT sequence, content_hash FROM %s WHERE env = :env ORDER BY sequence DESC LIMIT 1', $this->table),
                ['env' => $env],
            );

            $nextSequence = $tail === false ? 0 : ((int) $tail['sequence']) + 1;
            $prevHash = $tail === false ? AuditHashChain::GENESIS_HASH : (string) $tail['content_hash'];

            $entry = $builder($nextSequence, $prevHash);

            $conn->executeStatement(
                sprintf(
                    'INSERT INTO %s
                        (entry_id, sequence, event_type, actor_id, actor_identity_source, env,
                         build_id, git_sha, image_digest, schema_fingerprint_id, reason, occurred_at,
                         data, prev_hash, content_hash, signature)
                     VALUES
                        (:entry_id, :sequence, :event_type, :actor_id, :actor_identity_source, :env,
                         :build_id, :git_sha, :image_digest, :schema_fingerprint_id, :reason, :occurred_at,
                         :data, :prev_hash, :content_hash, :signature)',
                    $this->table,
                ),
                $this->toRow($entry),
            );

            return $entry;
        });
    }

    public function findByEnv(string $env, int $limit = 1000): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->table)
            ->where('env = :env')
            ->setParameter('env', $env)
            ->orderBy('sequence', 'ASC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(fn (array $row) => $this->fromRow($row), $rows);
    }

    public function findAll(
        ?string $env = null,
        ?string $actorId = null,
        ?string $buildId = null,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null,
        int $limit = 1000,
    ): array {
        $qb = $this->queryBuilder($env, $actorId, $buildId, $from, $to)
            ->orderBy('env', 'ASC')
            ->addOrderBy('sequence', 'ASC')
            ->setMaxResults($limit);

        return array_map(fn (array $row) => $this->fromRow($row), $qb->executeQuery()->fetchAllAssociative());
    }

    public function stream(
        ?string $env = null,
        ?string $actorId = null,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null,
    ): \Generator {
        $qb = $this->queryBuilder($env, $actorId, null, $from, $to)
            ->orderBy('env', 'ASC')
            ->addOrderBy('sequence', 'ASC');

        $result = $qb->executeQuery();

        while ($row = $result->fetchAssociative()) {
            yield $this->fromRow($row);
        }
    }

    private function queryBuilder(
        ?string $env,
        ?string $actorId,
        ?string $buildId,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
    ): \Doctrine\DBAL\Query\QueryBuilder {
        $qb = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->table);

        if ($env !== null) {
            $qb->andWhere('env = :env')->setParameter('env', $env);
        }
        if ($actorId !== null) {
            $qb->andWhere('actor_id = :actor_id')->setParameter('actor_id', $actorId);
        }
        if ($buildId !== null) {
            $qb->andWhere('build_id = :build_id')->setParameter('build_id', $buildId);
        }
        if ($from !== null) {
            $qb->andWhere('occurred_at >= :from')->setParameter('from', $from->format(\DateTimeInterface::ATOM));
        }
        if ($to !== null) {
            $qb->andWhere('occurred_at <= :to')->setParameter('to', $to->format(\DateTimeInterface::ATOM));
        }

        return $qb;
    }

    /** @return array<string, mixed> */
    private function toRow(AuditEntry $entry): array
    {
        return [
            'entry_id' => $entry->entryId,
            'sequence' => $entry->sequence,
            'event_type' => $entry->eventType,
            'actor_id' => $entry->actorId,
            'actor_identity_source' => $entry->actorIdentitySource,
            'env' => $entry->env,
            'build_id' => $entry->buildId,
            'git_sha' => $entry->gitSha,
            'image_digest' => $entry->imageDigest,
            'schema_fingerprint_id' => $entry->schemaFingerprintId,
            'reason' => $entry->reason,
            'occurred_at' => $entry->occurredAt,
            'data' => json_encode($entry->data, JSON_THROW_ON_ERROR),
            'prev_hash' => $entry->prevHash,
            'content_hash' => $entry->contentHash,
            'signature' => $entry->signature,
        ];
    }

    /** @param array<string, mixed> $row */
    private function fromRow(array $row): AuditEntry
    {
        return new AuditEntry(
            entryId: (string) $row['entry_id'],
            sequence: (int) $row['sequence'],
            eventType: (string) $row['event_type'],
            actorId: (string) $row['actor_id'],
            actorIdentitySource: (string) $row['actor_identity_source'],
            env: (string) $row['env'],
            buildId: (string) $row['build_id'],
            gitSha: (string) $row['git_sha'],
            imageDigest: (string) $row['image_digest'],
            schemaFingerprintId: (string) $row['schema_fingerprint_id'],
            reason: $row['reason'] !== null ? (string) $row['reason'] : null,
            occurredAt: (string) $row['occurred_at'],
            data: (array) json_decode((string) $row['data'], true, 512, JSON_THROW_ON_ERROR),
            prevHash: (string) $row['prev_hash'],
            contentHash: (string) $row['content_hash'],
            signature: (string) $row['signature'],
        );
    }
}
