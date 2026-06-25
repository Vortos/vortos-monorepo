<?php

declare(strict_types=1);

namespace Vortos\Backup\Catalog;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Vortos\Backup\Domain\BackupArtifact;

/**
 * Append-only catalog writer (DBAL).
 *
 * Rows are INSERTed once and never UPDATEd — a recorded backup's metadata/checksum can
 * never be silently altered (enforced by a DB trigger that rejects UPDATE). DELETE is
 * permitted for retention only, via {@see forget()}.
 */
final class DbalBackupCatalogRepository implements BackupCatalogRepositoryInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
    ) {}

    public function record(BackupArtifact $artifact): void
    {
        $data = $artifact->toArray();

        try {
            $this->connection->insert($this->table, [
                'id' => $data['id'],
                'engine' => $data['engine'],
                'kind' => $data['kind'],
                'environment' => $data['environment'],
                'created_at' => $data['created_at'],
                'size_bytes' => $data['size_bytes'],
                'checksum_algo' => $data['checksum_algo'],
                'checksum_hex' => $data['checksum_hex'],
                'store_key' => $data['store_key'],
                'codec' => $data['codec'],
                'source_ref' => json_encode($data['source_ref'], JSON_THROW_ON_ERROR),
                'parent_id' => $data['parent_id'],
                'schema_fingerprint' => $data['schema_fingerprint'],
                'encryption_provider' => $data['encryption_provider'] ?? null,
                'encryption_recipient' => $data['encryption_recipient'] ?? null,
                'encryption_aead_id' => $data['encryption_aead_id'] ?? null,
                'secondary_store_key' => $data['secondary_store_key'] ?? null,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw BackupAlreadyExistsException::forId($data['id']);
        }
    }

    public function forget(string $backupId): void
    {
        $this->connection->delete($this->table, ['id' => $backupId]);
    }
}
