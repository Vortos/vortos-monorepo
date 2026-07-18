<?php

declare(strict_types=1);

namespace Vortos\Search\Index\Dbal;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Vortos\Search\Document\SearchDocument;
use Vortos\Search\Index\SearchIndexWriterInterface;
use Vortos\Search\Observability\NullSearchMetrics;
use Vortos\Search\Observability\SearchMetricsInterface;

/**
 * DBAL index writer. Upserts portably (delete-then-insert in one transaction on the natural key
 * tenant+type+entity) rather than Postgres `ON CONFLICT`, so indexing works on any DBAL engine;
 * the Postgres `search_vector` is a generated column and is never written here. Volume is one
 * row per domain event, so the extra delete is negligible.
 */
final class DbalSearchIndexWriter implements SearchIndexWriterInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SearchMetricsInterface $metrics = new NullSearchMetrics(),
        private readonly ?ClockInterface $clock = null,
        private readonly string $table = 'vortos_search_documents',
    ) {
    }

    public function upsert(SearchDocument $document): void
    {
        $this->connection->transactional(function (Connection $conn) use ($document): void {
            $this->deleteRow($conn, $document->type, $document->entityId, $document->tenantId);
            $conn->insert($this->table, [
                'id'              => $this->newId(),
                'tenant_id'       => $document->tenantId,
                'doc_type'        => $document->type,
                'entity_id'       => $document->entityId,
                'title'           => $document->title,
                'subtitle'        => $document->subtitle,
                'body'            => $document->body,
                'keywords'        => $document->keywordBlob(),
                'deeplink'        => $document->deeplink,
                'permission'      => $document->permission,
                'owner_member_id' => $document->ownerMemberId,
                'meta'            => json_encode($document->meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at'      => $this->now(),
            ]);
        });

        $this->metrics->indexUpserted($document->type);
    }

    public function delete(string $type, string $entityId, string $tenantId): void
    {
        $removed = $this->deleteRow($this->connection, $type, $entityId, $tenantId);
        if ($removed > 0) {
            $this->metrics->indexDeleted($type, $removed);
        }
    }

    public function purgeType(string $type, string $tenantId): int
    {
        $removed = (int) $this->connection->executeStatement(
            "DELETE FROM {$this->table} WHERE tenant_id = :tenant AND doc_type = :type",
            ['tenant' => $tenantId, 'type' => $type],
        );
        if ($removed > 0) {
            $this->metrics->indexDeleted($type, $removed);
        }

        return $removed;
    }

    private function deleteRow(Connection $conn, string $type, string $entityId, string $tenantId): int
    {
        return (int) $conn->executeStatement(
            "DELETE FROM {$this->table} WHERE tenant_id = :tenant AND doc_type = :type AND entity_id = :entity",
            ['tenant' => $tenantId, 'type' => $type, 'entity' => $entityId],
        );
    }

    private function now(): string
    {
        $when = $this->clock?->now() ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $when->format('Y-m-d H:i:s.u');
    }

    private function newId(): string
    {
        // UUIDv4 without pulling a hard dependency on symfony/uid.
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
