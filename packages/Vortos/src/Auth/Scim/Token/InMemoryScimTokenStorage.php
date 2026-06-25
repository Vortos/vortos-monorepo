<?php

declare(strict_types=1);

namespace Vortos\Auth\Scim\Token;

final class InMemoryScimTokenStorage implements ScimTokenStorageInterface
{
    /** @var array<string, ScimTokenRecord> keyed by hashed token */
    private array $byHash = [];

    /** @var array<string, string> tokenId → hashedToken */
    private array $idIndex = [];

    public function findByHash(string $hashedToken): ?ScimTokenRecord
    {
        $record = $this->byHash[$hashedToken] ?? null;

        return $record !== null && $record->active ? $record : null;
    }

    public function save(ScimTokenRecord $record): void
    {
        $this->byHash[$record->hashedToken] = $record;
        $this->idIndex[$record->id] = $record->hashedToken;
    }

    public function revoke(string $tokenId): void
    {
        $hash = $this->idIndex[$tokenId] ?? null;
        if ($hash === null) {
            return;
        }

        $record = $this->byHash[$hash] ?? null;
        if ($record === null) {
            return;
        }

        $this->byHash[$hash] = new ScimTokenRecord(
            id: $record->id,
            tenantId: $record->tenantId,
            hashedToken: $record->hashedToken,
            scopes: $record->scopes,
            allowedCidrs: $record->allowedCidrs,
            active: false,
            createdAt: $record->createdAt,
            expiresAt: $record->expiresAt,
            lastUsedAt: $record->lastUsedAt,
        );
    }

    public function findByTenantId(string $tenantId): array
    {
        return array_values(array_filter(
            $this->byHash,
            static fn(ScimTokenRecord $r) => $r->tenantId === $tenantId && $r->active,
        ));
    }

    public function updateLastUsedAt(string $tokenId, \DateTimeImmutable $at): void
    {
        $hash = $this->idIndex[$tokenId] ?? null;
        if ($hash === null) {
            return;
        }

        $record = $this->byHash[$hash] ?? null;
        if ($record === null) {
            return;
        }

        $this->byHash[$hash] = new ScimTokenRecord(
            id: $record->id,
            tenantId: $record->tenantId,
            hashedToken: $record->hashedToken,
            scopes: $record->scopes,
            allowedCidrs: $record->allowedCidrs,
            active: $record->active,
            createdAt: $record->createdAt,
            expiresAt: $record->expiresAt,
            lastUsedAt: $at,
        );
    }
}
