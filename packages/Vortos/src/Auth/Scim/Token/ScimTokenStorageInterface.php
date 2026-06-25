<?php

declare(strict_types=1);

namespace Vortos\Auth\Scim\Token;

interface ScimTokenStorageInterface
{
    public function findByHash(string $hashedToken): ?ScimTokenRecord;

    public function save(ScimTokenRecord $record): void;

    public function revoke(string $tokenId): void;

    /** @return list<ScimTokenRecord> */
    public function findByTenantId(string $tenantId): array;

    public function updateLastUsedAt(string $tokenId, \DateTimeImmutable $at): void;
}
