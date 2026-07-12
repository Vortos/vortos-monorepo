<?php

declare(strict_types=1);

namespace Vortos\Audit\SavedView\Dbal;

use Doctrine\DBAL\Connection;
use Vortos\Audit\SavedView\AuditSavedView;
use Vortos\Audit\SavedView\AuditSavedViewStoreInterface;

/**
 * DBAL-backed saved-view store. Ownership is enforced in the WHERE clause of every read and
 * delete — a caller can only touch rows matching their own (tenant_id, owner_id), so scope
 * isolation holds even before RLS. tenant_id IS NULL selects the platform-scope views.
 */
final class DbalAuditSavedViewStore implements AuditSavedViewStoreInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string     $table = 'vortos_audit_saved_views',
    ) {}

    public function save(AuditSavedView $view): void
    {
        $this->connection->insert($this->table, [
            'id'         => $view->id,
            'tenant_id'  => $view->tenantId,
            'owner_id'   => $view->ownerId,
            'name'       => $view->name,
            'filters'    => json_encode($view->filters, JSON_THROW_ON_ERROR),
            'created_at' => $view->createdAt->format('Y-m-d\TH:i:sP'),
        ]);
    }

    public function listFor(?string $tenantId, string $ownerId): array
    {
        $tenantClause = $tenantId === null ? 'tenant_id IS NULL' : 'tenant_id = :tenant_id';
        $params       = ['owner_id' => $ownerId];
        if ($tenantId !== null) {
            $params['tenant_id'] = $tenantId;
        }

        $rows = $this->connection->fetchAllAssociative(
            "SELECT * FROM {$this->table} WHERE {$tenantClause} AND owner_id = :owner_id ORDER BY created_at DESC, id DESC",
            $params,
        );

        return array_map([$this, 'fromRow'], $rows);
    }

    public function delete(string $id, ?string $tenantId, string $ownerId): bool
    {
        $tenantClause = $tenantId === null ? 'tenant_id IS NULL' : 'tenant_id = :tenant_id';
        $params       = ['id' => $id, 'owner_id' => $ownerId];
        if ($tenantId !== null) {
            $params['tenant_id'] = $tenantId;
        }

        return $this->connection->executeStatement(
            "DELETE FROM {$this->table} WHERE id = :id AND {$tenantClause} AND owner_id = :owner_id",
            $params,
        ) > 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function fromRow(array $row): AuditSavedView
    {
        /** @var array<string, mixed> $filters */
        $filters = json_decode((string) $row['filters'], true, 512, JSON_THROW_ON_ERROR) ?: [];

        return new AuditSavedView(
            id:        (string) $row['id'],
            tenantId:  $row['tenant_id'] !== null ? (string) $row['tenant_id'] : null,
            ownerId:   (string) $row['owner_id'],
            name:      (string) $row['name'],
            filters:   $filters,
            createdAt: new \DateTimeImmutable((string) $row['created_at']),
        );
    }
}
