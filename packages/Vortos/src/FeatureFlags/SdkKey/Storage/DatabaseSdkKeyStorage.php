<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\SdkKey\Storage;

use Doctrine\DBAL\Connection;
use Vortos\FeatureFlags\SdkKey\SdkKey;

final class DatabaseSdkKeyStorage implements SdkKeyStorageInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
    ) {}

    public function save(SdkKey $key): void
    {
        $this->connection->executeStatement(
            'INSERT INTO ' . $this->table . '
                 (id, name, key_prefix, key_hash, kind, project_id, environment,
                  created_at, created_by, successor_key_id, grace_period_ends_at,
                  expires_at, revoked_at, last_used_at, ip_allowlist)
             VALUES
                 (:id, :name, :key_prefix, :key_hash, :kind, :project_id, :environment,
                  :created_at, :created_by, :successor_key_id, :grace_period_ends_at,
                  :expires_at, :revoked_at, :last_used_at, :ip_allowlist)
             ON CONFLICT (id) DO UPDATE SET
                 name                = EXCLUDED.name,
                 successor_key_id    = EXCLUDED.successor_key_id,
                 grace_period_ends_at = EXCLUDED.grace_period_ends_at,
                 expires_at          = EXCLUDED.expires_at,
                 revoked_at          = EXCLUDED.revoked_at,
                 last_used_at        = EXCLUDED.last_used_at,
                 ip_allowlist        = EXCLUDED.ip_allowlist',
            $this->toRow($key),
        );
    }

    public function findById(string $id): ?SdkKey
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->table)
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $this->hydrate($row) : null;
    }

    public function findByPrefix(string $prefix): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->table)
            ->where('key_prefix = :prefix')
            ->setParameter('prefix', $prefix)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->hydrate(...), $rows);
    }

    public function findByProjectAndEnv(string $projectId, string $environment): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->table)
            ->where('project_id = :project_id AND environment = :environment')
            ->setParameter('project_id', $projectId)
            ->setParameter('environment', $environment)
            ->orderBy('created_at', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->hydrate(...), $rows);
    }

    public function updateLastUsed(string $id, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(
            'UPDATE ' . $this->table . ' SET last_used_at = :at WHERE id = :id',
            ['at' => $at->format('Y-m-d H:i:s'), 'id' => $id],
        );
    }

    public function revoke(string $id, \DateTimeImmutable $revokedAt): void
    {
        $this->connection->executeStatement(
            'UPDATE ' . $this->table . ' SET revoked_at = :revoked_at WHERE id = :id',
            ['revoked_at' => $revokedAt->format('Y-m-d H:i:s'), 'id' => $id],
        );
    }

    private function toRow(SdkKey $key): array
    {
        return [
            'id'                  => $key->id,
            'name'                => $key->name,
            'key_prefix'          => $key->keyPrefix,
            'key_hash'            => $key->keyHash,
            'kind'                => $key->kind,
            'project_id'          => $key->projectId,
            'environment'         => $key->environment,
            'created_at'          => $key->createdAt->format('Y-m-d H:i:s'),
            'created_by'          => $key->createdBy,
            'successor_key_id'    => $key->successorKeyId,
            'grace_period_ends_at' => $key->gracePeriodEndsAt?->format('Y-m-d H:i:s'),
            'expires_at'          => $key->expiresAt?->format('Y-m-d H:i:s'),
            'revoked_at'          => $key->revokedAt?->format('Y-m-d H:i:s'),
            'last_used_at'        => $key->lastUsedAt?->format('Y-m-d H:i:s'),
            'ip_allowlist'        => $key->ipAllowlist !== null ? json_encode($key->ipAllowlist) : null,
        ];
    }

    private function hydrate(array $row): SdkKey
    {
        return new SdkKey(
            id:                 (string) $row['id'],
            name:               (string) $row['name'],
            keyPrefix:          (string) $row['key_prefix'],
            keyHash:            (string) $row['key_hash'],
            kind:               (string) $row['kind'],
            projectId:          (string) $row['project_id'],
            environment:        (string) $row['environment'],
            createdAt:          new \DateTimeImmutable($row['created_at']),
            createdBy:          (string) $row['created_by'],
            successorKeyId:     isset($row['successor_key_id']) ? (string) $row['successor_key_id'] : null,
            gracePeriodEndsAt:  isset($row['grace_period_ends_at']) ? new \DateTimeImmutable($row['grace_period_ends_at']) : null,
            expiresAt:          isset($row['expires_at']) ? new \DateTimeImmutable($row['expires_at']) : null,
            revokedAt:          isset($row['revoked_at']) ? new \DateTimeImmutable($row['revoked_at']) : null,
            lastUsedAt:         isset($row['last_used_at']) ? new \DateTimeImmutable($row['last_used_at']) : null,
            ipAllowlist:        isset($row['ip_allowlist']) ? json_decode($row['ip_allowlist'], true) : null,
        );
    }
}
