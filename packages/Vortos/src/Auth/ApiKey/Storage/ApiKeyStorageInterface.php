<?php

declare(strict_types=1);

namespace Vortos\Auth\ApiKey\Storage;

use Vortos\Auth\ApiKey\ApiKeyRecord;

interface ApiKeyStorageInterface
{
    /**
     * Look up an API key by its hashed value.
     *
     * @param string $hashedKey SHA-256 hex digest of the raw key.
     * @return ApiKeyRecord|null Null when not found or revoked.
     */
    public function findByHash(string $hashedKey): ?ApiKeyRecord;

    /** Store a new API key record. */
    public function save(ApiKeyRecord $record): void;

    /** Revoke a key by its ID (marks it as inactive). */
    public function revoke(string $keyId): void;

    /**
     * List all active keys for a user.
     *
     * @return list<ApiKeyRecord>
     */
    public function findByUserId(string $userId): array;
}
