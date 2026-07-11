<?php

declare(strict_types=1);

namespace Vortos\Audit\Storage\Dbal;

use Doctrine\DBAL\Connection;
use Vortos\Audit\Contract\AuditRecorderInterface;
use Vortos\Audit\Event\AuditEvent;
use Vortos\Audit\Integrity\AuditHashChain;
use Vortos\Audit\Retention\AuditRetentionSourceInterface;
use Vortos\Audit\Storage\AuditReaderInterface;
use Vortos\Audit\Storage\StoredAuditEvent;

/**
 * Append-only, per-chain hash-chained audit store (P2).
 *
 * Each event is committed to the chain identified by {@see AuditEvent::chainKey()} —
 * one chain for all platform events, one per tenant. A transaction-scoped advisory lock
 * keyed on the chain serialises appends WITHIN a chain (so sequence + prev_hash stay
 * consistent) while letting different chains — different tenants — append concurrently.
 *
 * This is a valid synchronous {@see AuditRecorderInterface}; P3 layers Kafka in front so
 * the request path enqueues and this runs in the consumer instead.
 */
final class DbalAuditStore implements AuditRecorderInterface, AuditReaderInterface, AuditRetentionSourceInterface
{
    public function __construct(
        private readonly Connection      $connection,
        private readonly AuditHashChain  $chain,
        private readonly string          $hmacKey,
        private readonly string          $table = 'vortos_audit_events',
    ) {}

    public function record(AuditEvent $event): void
    {
        $chainKey = $event->chainKey();

        $this->connection->transactional(function (Connection $conn) use ($event, $chainKey): void {
            // Per-chain advisory lock: serialise appends to THIS chain only. Different
            // chains (tenants) hash independently and never contend.
            $conn->executeStatement('SELECT pg_advisory_xact_lock(:k)', ['k' => $this->lockKey($chainKey)]);

            $tail = $this->chainTail($chainKey);
            $sequence = ($tail['sequence'] ?? 0) + 1;
            $prevHash = $tail['content_hash'] ?? AuditHashChain::GENESIS_HASH;

            $stored = $this->chain->chain($event, $chainKey, $sequence, $prevHash, $this->hmacKey);

            $conn->insert($this->table, $this->toRow($stored));
        });
    }

    public function chainTail(string $chainKey): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT sequence, content_hash FROM {$this->table} WHERE chain_key = :ck ORDER BY sequence DESC LIMIT 1",
            ['ck' => $chainKey],
        );

        return $row === false
            ? null
            : ['sequence' => (int) $row['sequence'], 'content_hash' => (string) $row['content_hash']];
    }

    public function readChain(string $chainKey, int $afterSequence, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT * FROM {$this->table}
              WHERE chain_key = :ck AND sequence > :after
              ORDER BY sequence ASC
              LIMIT :lim",
            ['ck' => $chainKey, 'after' => $afterSequence, 'lim' => max(1, $limit)],
        );

        return array_map([$this, 'fromRow'], $rows);
    }

    public function chainsWithRecordsBefore(\DateTimeImmutable $cutoff): array
    {
        $rows = $this->connection->fetchFirstColumn(
            "SELECT DISTINCT chain_key FROM {$this->table} WHERE occurred_at < :cut ORDER BY chain_key",
            ['cut' => $cutoff->format('Y-m-d\TH:i:s.uP')],
        );

        return array_map('strval', $rows);
    }

    public function deleteChainUpTo(string $chainKey, int $sequence): int
    {
        return (int) $this->connection->executeStatement(
            "DELETE FROM {$this->table} WHERE chain_key = :ck AND sequence <= :seq",
            ['ck' => $chainKey, 'seq' => $sequence],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(StoredAuditEvent $stored): array
    {
        $e = $stored->event;

        return [
            'id'           => $e->id,
            'scope'        => $e->scope->value,
            'tenant_id'    => $e->tenantId,
            'actor'        => json_encode($e->actor->toArray(), JSON_THROW_ON_ERROR),
            'action'       => $e->action,
            'target'       => $e->target !== null ? json_encode($e->target->toArray(), JSON_THROW_ON_ERROR) : null,
            'sensitivity'  => $e->sensitivity->value,
            'outcome'      => $e->outcome->value,
            'source'       => json_encode($e->source->toArray(), JSON_THROW_ON_ERROR),
            'context'      => json_encode($e->context, JSON_THROW_ON_ERROR),
            'occurred_at'  => $e->occurredAt->format('Y-m-d\TH:i:s.uP'),
            'chain_key'    => $stored->chainKey,
            'sequence'     => $stored->sequence,
            'prev_hash'    => $stored->prevHash,
            'content_hash' => $stored->contentHash,
            'signature'    => $stored->signature,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function fromRow(array $row): StoredAuditEvent
    {
        $event = AuditEvent::fromArray([
            'id'          => $row['id'],
            'scope'       => $row['scope'],
            'tenant_id'   => $row['tenant_id'],
            'actor'       => json_decode((string) $row['actor'], true, 512, JSON_THROW_ON_ERROR),
            'action'      => $row['action'],
            'target'      => $row['target'] !== null ? json_decode((string) $row['target'], true, 512, JSON_THROW_ON_ERROR) : null,
            'sensitivity' => $row['sensitivity'],
            'outcome'     => $row['outcome'],
            'source'      => json_decode((string) $row['source'], true, 512, JSON_THROW_ON_ERROR),
            'context'     => json_decode((string) ($row['context'] ?? '[]'), true, 512, JSON_THROW_ON_ERROR),
            'occurred_at' => $row['occurred_at'],
        ]);

        return new StoredAuditEvent(
            event:       $event,
            chainKey:    (string) $row['chain_key'],
            sequence:    (int) $row['sequence'],
            prevHash:    (string) $row['prev_hash'],
            contentHash: (string) $row['content_hash'],
            signature:   (string) $row['signature'],
        );
    }

    /** Deterministic 63-bit advisory-lock key from the chain key. */
    private function lockKey(string $chainKey): int
    {
        // crc32 is unsigned 32-bit; namespace it into a stable signed-bigint range.
        return 0x41554449 << 20 | crc32($chainKey) % 0xFFFFF; // 'AUDI' namespace | bucket
    }
}
