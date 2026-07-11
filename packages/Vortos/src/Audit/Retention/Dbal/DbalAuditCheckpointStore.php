<?php

declare(strict_types=1);

namespace Vortos\Audit\Retention\Dbal;

use Doctrine\DBAL\Connection;
use Vortos\Audit\Retention\AuditCheckpoint;
use Vortos\Audit\Retention\AuditCheckpointStoreInterface;

/**
 * DBAL checkpoint store. Rows accumulate (one per archive run) so the full object-key
 * history is preserved for retrieval; {@see find} returns the highest-sequence row, which
 * is the current archival frontier.
 */
final class DbalAuditCheckpointStore implements AuditCheckpointStoreInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string     $table = 'vortos_audit_checkpoints',
    ) {}

    public function find(string $chainKey): ?AuditCheckpoint
    {
        $row = $this->connection->fetchAssociative(
            "SELECT * FROM {$this->table} WHERE chain_key = :ck ORDER BY last_sequence DESC LIMIT 1",
            ['ck' => $chainKey],
        );

        if ($row === false) {
            return null;
        }

        return new AuditCheckpoint(
            chainKey:        (string) $row['chain_key'],
            lastSequence:    (int) $row['last_sequence'],
            lastContentHash: (string) $row['last_content_hash'],
            archivedAt:      new \DateTimeImmutable((string) $row['archived_at']),
            objectKey:       (string) $row['object_key'],
            recordCount:     (int) $row['record_count'],
        );
    }

    public function save(AuditCheckpoint $checkpoint): void
    {
        $this->connection->insert($this->table, [
            'id'                => (string) new \Symfony\Component\Uid\UuidV7(),
            'chain_key'         => $checkpoint->chainKey,
            'last_sequence'     => $checkpoint->lastSequence,
            'last_content_hash' => $checkpoint->lastContentHash,
            'archived_at'       => $checkpoint->archivedAt->format('Y-m-d\TH:i:s.uP'),
            'object_key'        => $checkpoint->objectKey,
            'record_count'      => $checkpoint->recordCount,
        ]);
    }
}
