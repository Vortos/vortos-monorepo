<?php

declare(strict_types=1);

namespace Vortos\Auth\ApiKey;

use Vortos\Auth\ApiKey\Storage\ApiKeyStorageInterface;

/**
 * Generates, validates, and revokes API keys.
 *
 * ## Key format
 *
 *   vrtk_{base64url(random_bytes(32))}
 *
 * The 'vrtk_' prefix makes Vortos API keys visually identifiable in logs and
 * easy to filter out of secret scanning rules.
 *
 * ## Storage security
 *
 * Only the SHA-256 hash of the key is persisted. The raw key is returned
 * once on generation and is never stored — if lost, a new key must be issued.
 *
 * ## Scope format
 *
 * Scopes follow the 'resource:action' convention (e.g., 'athletes:read', 'users:write').
 * Scopes are validated against #[RequiresApiKey(scopes: [...])] at request time.
 */
final class ApiKeyService
{
    public function __construct(
        private readonly ApiKeyStorageInterface $storage,
    ) {}

    /**
     * Generates a new API key and returns the raw (unhashed) key.
     *
     * The raw key is returned ONCE. Store it securely — it cannot be retrieved later.
     *
     * @param list<string>            $scopes
     */
    public function generate(
        string              $userId,
        string              $name,
        array               $scopes = [],
        ?\DateTimeImmutable $expiresAt = null,
    ): string {
        $rawKey    = 'vrtk_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $hashedKey = hash('sha256', $rawKey);

        $record = new ApiKeyRecord(
            id:          bin2hex(random_bytes(16)),
            userId:      $userId,
            name:        $name,
            hashedKey:   $hashedKey,
            scopes:      $scopes,
            active:      true,
            createdAt:   new \DateTimeImmutable(),
            expiresAt:   $expiresAt,
            lastUsedAt:  null,
        );

        $this->storage->save($record);

        return $rawKey;
    }

    /**
     * Validates a raw API key and returns the record if valid and has all required scopes.
     *
     * @param list<string> $requiredScopes
     */
    public function validate(string $rawKey, array $requiredScopes = []): ?ApiKeyRecord
    {
        $hashedKey = hash('sha256', $rawKey);
        $record    = $this->storage->findByHash($hashedKey);

        if ($record === null || !$record->active || $record->isExpired()) {
            return null;
        }

        if (!empty($requiredScopes) && !$record->hasAllScopes($requiredScopes)) {
            return null;
        }

        return $record;
    }

    public function revoke(string $keyId): void
    {
        $this->storage->revoke($keyId);
    }

    /** @return list<ApiKeyRecord> */
    public function listForUser(string $userId): array
    {
        return $this->storage->findByUserId($userId);
    }
}
