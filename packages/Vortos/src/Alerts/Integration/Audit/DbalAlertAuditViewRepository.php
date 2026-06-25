<?php

declare(strict_types=1);

namespace Vortos\Alerts\Integration\Audit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Vortos\Observability\Audit\AuditHashChain;

/** Single-writer-per-env via Postgres advisory lock — the same discipline as {@see \Vortos\Observability\Audit\DbalDeployAuditViewRepository}. */
final class DbalAlertAuditViewRepository implements AlertAuditViewRepositoryInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
    ) {}

    public function appendNext(string $env, callable $builder): AlertAuditEntry
    {
        return $this->connection->transactional(function (Connection $conn) use ($env, $builder): AlertAuditEntry {
            if ($conn->getDatabasePlatform() instanceof PostgreSQLPlatform) {
                $conn->executeStatement('SELECT pg_advisory_xact_lock(hashtext(:env))', ['env' => 'alerts_audit_' . $env]);
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
                    'INSERT INTO %s (entry_id, sequence, env, event_type, fingerprint, actor_id, occurred_at, data, prev_hash, content_hash, signature)
                     VALUES (:entry_id, :sequence, :env, :event_type, :fingerprint, :actor_id, :occurred_at, :data, :prev_hash, :content_hash, :signature)',
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

        return array_map($this->fromRow(...), $rows);
    }

    /** @return array<string, mixed> */
    private function toRow(AlertAuditEntry $entry): array
    {
        return [
            'entry_id' => $entry->entryId,
            'sequence' => $entry->sequence,
            'env' => $entry->env,
            'event_type' => $entry->eventType,
            'fingerprint' => $entry->fingerprint,
            'actor_id' => $entry->actorId,
            'occurred_at' => $entry->occurredAt,
            'data' => json_encode($entry->data, JSON_THROW_ON_ERROR),
            'prev_hash' => $entry->prevHash,
            'content_hash' => $entry->contentHash,
            'signature' => $entry->signature,
        ];
    }

    /** @param array<string, mixed> $row */
    private function fromRow(array $row): AlertAuditEntry
    {
        return new AlertAuditEntry(
            entryId: (string) $row['entry_id'],
            sequence: (int) $row['sequence'],
            env: (string) $row['env'],
            eventType: (string) $row['event_type'],
            fingerprint: (string) $row['fingerprint'],
            actorId: (string) $row['actor_id'],
            occurredAt: (string) $row['occurred_at'],
            data: (array) json_decode((string) $row['data'], true, 512, JSON_THROW_ON_ERROR),
            prevHash: (string) $row['prev_hash'],
            contentHash: (string) $row['content_hash'],
            signature: (string) $row['signature'],
        );
    }
}
