<?php

declare(strict_types=1);

namespace Vortos\Audit\Export\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Vortos\Audit\Enum\AuditExportStatus;
use Vortos\Audit\Enum\Scope;
use Vortos\Audit\Export\AuditExportFilter;
use Vortos\Audit\Export\AuditExportJob;
use Vortos\Audit\Export\AuditExportJobStoreInterface;

/**
 * DBAL store for export jobs. `save()` upserts by id so the consumer can advance a job's
 * status across separate deliveries without racing an insert.
 */
final class DbalAuditExportJobStore implements AuditExportJobStoreInterface
{
    private const DATE_FMT = 'Y-m-d\TH:i:s.uP';

    public function __construct(
        private readonly Connection $connection,
        private readonly string     $table = 'vortos_audit_export_jobs',
    ) {}

    public function save(AuditExportJob $job): void
    {
        $row    = $this->toRow($job);
        $exists = $this->connection->fetchOne(
            "SELECT 1 FROM {$this->table} WHERE id = :id",
            ['id' => $job->id],
        );

        if ($exists !== false) {
            unset($row['id']);
            $this->connection->update($this->table, $row, ['id' => $job->id]);
            return;
        }

        $this->connection->insert($this->table, $this->toRow($job));
    }

    public function find(string $id): ?AuditExportJob
    {
        $row = $this->connection->fetchAssociative(
            "SELECT * FROM {$this->table} WHERE id = :id",
            ['id' => $id],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function listForScope(Scope $scope, ?string $tenantId, int $limit = 25): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE scope = :scope AND "
            . ($tenantId === null ? 'tenant_id IS NULL' : 'tenant_id = :tenant')
            . ' ORDER BY created_at DESC LIMIT :limit';

        $params = ['scope' => $scope->value, 'limit' => max(1, $limit)];
        $types  = ['limit' => ParameterType::INTEGER];
        if ($tenantId !== null) {
            $params['tenant'] = $tenantId;
        }

        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);

        return array_map(fn (array $r): AuditExportJob => $this->hydrate($r), $rows);
    }

    public function findExpired(\DateTimeImmutable $now, int $limit = 100): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT * FROM {$this->table}
              WHERE status = :ready AND expires_at IS NOT NULL AND expires_at <= :now
              ORDER BY expires_at ASC LIMIT :limit",
            ['ready' => AuditExportStatus::Ready->value, 'now' => $now->format(self::DATE_FMT), 'limit' => max(1, $limit)],
            ['limit' => ParameterType::INTEGER],
        );

        return array_map(fn (array $r): AuditExportJob => $this->hydrate($r), $rows);
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(AuditExportJob $job): array
    {
        return [
            'id'                    => $job->id,
            'scope'                 => $job->scope->value,
            'tenant_id'             => $job->tenantId,
            'requested_by_actor_id' => $job->requestedByActorId,
            'requested_by_label'    => $job->requestedByLabel,
            'filter'                => json_encode($job->filter->toArray(), JSON_THROW_ON_ERROR),
            'status'                => $job->status()->value,
            'record_count'          => $job->recordCount(),
            'byte_size'             => $job->byteSize(),
            'content_sha256'        => $job->contentSha256(),
            'body_key'              => $job->bodyKey(),
            'manifest_key'          => $job->manifestKey(),
            'error'                 => $job->error(),
            'created_at'            => $job->createdAt->format(self::DATE_FMT),
            'updated_at'            => $job->updatedAt()->format(self::DATE_FMT),
            'expires_at'            => $job->expiresAt()?->format(self::DATE_FMT),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): AuditExportJob
    {
        /** @var array<string, mixed> $filter */
        $filter = json_decode((string) $row['filter'], true, 512, JSON_THROW_ON_ERROR);

        return AuditExportJob::rehydrate(
            id:                 (string) $row['id'],
            scope:              Scope::from((string) $row['scope']),
            tenantId:           $row['tenant_id'] !== null ? (string) $row['tenant_id'] : null,
            requestedByActorId: (string) $row['requested_by_actor_id'],
            requestedByLabel:   $row['requested_by_label'] !== null ? (string) $row['requested_by_label'] : null,
            filter:             AuditExportFilter::fromArray($filter),
            status:             AuditExportStatus::from((string) $row['status']),
            recordCount:        $row['record_count'] !== null ? (int) $row['record_count'] : null,
            byteSize:           $row['byte_size'] !== null ? (int) $row['byte_size'] : null,
            contentSha256:      $row['content_sha256'] !== null ? (string) $row['content_sha256'] : null,
            bodyKey:            $row['body_key'] !== null ? (string) $row['body_key'] : null,
            manifestKey:        $row['manifest_key'] !== null ? (string) $row['manifest_key'] : null,
            error:              $row['error'] !== null ? (string) $row['error'] : null,
            createdAt:          new \DateTimeImmutable((string) $row['created_at']),
            updatedAt:          new \DateTimeImmutable((string) $row['updated_at']),
            expiresAt:          $row['expires_at'] !== null ? new \DateTimeImmutable((string) $row['expires_at']) : null,
        );
    }
}
