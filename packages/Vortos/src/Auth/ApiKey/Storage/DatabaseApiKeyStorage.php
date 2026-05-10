<?php

declare(strict_types=1);

namespace Vortos\Auth\ApiKey\Storage;

use Doctrine\DBAL\Connection;
use Vortos\Auth\ApiKey\ApiKeyRecord;

/**
 * DBAL-backed API key storage.
 *
 * Provides a full audit trail and management capabilities (list, revoke).
 * Required table: api_keys — created by the security migration.
 *
 * Use this in combination with RedisApiKeyStorage via a CachingApiKeyStorage
 * decorator (not provided here) for hot-path lookups with audit persistence.
 *
 * ## Schema
 *
 *   CREATE TABLE api_keys (
 *       id           VARCHAR(36)  PRIMARY KEY,
 *       user_id      VARCHAR(36)  NOT NULL,
 *       name         VARCHAR(255) NOT NULL,
 *       hashed_key   VARCHAR(64)  NOT NULL UNIQUE,
 *       scopes       JSONB        NOT NULL DEFAULT '[]',
 *       active       BOOLEAN      NOT NULL DEFAULT TRUE,
 *       created_at   TIMESTAMPTZ  NOT NULL,
 *       expires_at   TIMESTAMPTZ,
 *       last_used_at TIMESTAMPTZ
 *   );
 *   CREATE INDEX ON api_keys (user_id);
 *   CREATE INDEX ON api_keys (hashed_key);
 */
final class DatabaseApiKeyStorage implements ApiKeyStorageInterface
{
    public function __construct(private readonly Connection $connection) {}

    public function findByHash(string $hashedKey): ?ApiKeyRecord
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM api_keys WHERE hashed_key = ? AND active = TRUE',
            [$hashedKey],
        );

        return $row !== false ? $this->hydrate($row) : null;
    }

    public function save(ApiKeyRecord $record): void
    {
        $this->connection->executeStatement(
            'INSERT INTO api_keys (id, user_id, name, hashed_key, scopes, active, created_at, expires_at, last_used_at)
             VALUES (:id, :user_id, :name, :hashed_key, :scopes, :active, :created_at, :expires_at, :last_used_at)
             ON CONFLICT (id) DO UPDATE SET
                 name = EXCLUDED.name,
                 scopes = EXCLUDED.scopes,
                 active = EXCLUDED.active,
                 expires_at = EXCLUDED.expires_at,
                 last_used_at = EXCLUDED.last_used_at',
            [
                'id'           => $record->id,
                'user_id'      => $record->userId,
                'name'         => $record->name,
                'hashed_key'   => $record->hashedKey,
                'scopes'       => json_encode($record->scopes),
                'active'       => $record->active ? 'TRUE' : 'FALSE',
                'created_at'   => $record->createdAt->format(\DateTimeInterface::ATOM),
                'expires_at'   => $record->expiresAt?->format(\DateTimeInterface::ATOM),
                'last_used_at' => $record->lastUsedAt?->format(\DateTimeInterface::ATOM),
            ],
        );
    }

    public function revoke(string $keyId): void
    {
        $this->connection->executeStatement(
            'UPDATE api_keys SET active = FALSE WHERE id = ?',
            [$keyId],
        );
    }

    public function findByUserId(string $userId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM api_keys WHERE user_id = ? AND active = TRUE ORDER BY created_at DESC',
            [$userId],
        );

        return array_map($this->hydrate(...), $rows);
    }

    private function hydrate(array $row): ApiKeyRecord
    {
        $scopes = is_string($row['scopes'])
            ? json_decode($row['scopes'], true)
            : ($row['scopes'] ?? []);

        return new ApiKeyRecord(
            id:          $row['id'],
            userId:      $row['user_id'],
            name:        $row['name'],
            hashedKey:   $row['hashed_key'],
            scopes:      $scopes,
            active:      (bool) $row['active'],
            createdAt:   new \DateTimeImmutable($row['created_at']),
            expiresAt:   isset($row['expires_at']) ? new \DateTimeImmutable($row['expires_at']) : null,
            lastUsedAt:  isset($row['last_used_at']) ? new \DateTimeImmutable($row['last_used_at']) : null,
        );
    }
}
