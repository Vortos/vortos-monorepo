<?php

declare(strict_types=1);

namespace Vortos\Auth\Scim\Token;

final class ScimTokenService
{
    private const TOKEN_PREFIX = 'vsct_';
    private const LAST_USED_DEBOUNCE_SECONDS = 60;

    public function __construct(
        private readonly ScimTokenStorageInterface $storage,
    ) {}

    /**
     * Issue a new SCIM bearer token.
     *
     * Returns the raw token string — it is shown once and never stored.
     *
     * @param list<string> $scopes
     * @param list<string> $allowedCidrs
     * @return array{raw: string, record: ScimTokenRecord}
     */
    public function issue(
        string              $tenantId,
        array               $scopes = [],
        array               $allowedCidrs = [],
        ?\DateTimeImmutable $expiresAt = null,
    ): array {
        $raw = self::TOKEN_PREFIX . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $hash = hash('sha256', $raw);

        $record = new ScimTokenRecord(
            id:           bin2hex(random_bytes(16)),
            tenantId:     $tenantId,
            hashedToken:  $hash,
            scopes:       $scopes,
            allowedCidrs: $allowedCidrs,
            active:       true,
            createdAt:    new \DateTimeImmutable(),
            expiresAt:    $expiresAt,
            lastUsedAt:   null,
        );

        $this->storage->save($record);

        return ['raw' => $raw, 'record' => $record];
    }

    /**
     * Validate a raw bearer token. Returns null if invalid/expired/revoked.
     * Debounces lastUsedAt updates (one write per 60s per token).
     */
    public function validate(string $rawToken): ?ScimTokenRecord
    {
        $hash = hash('sha256', $rawToken);
        $record = $this->storage->findByHash($hash);

        if ($record === null || !$record->active || $record->isExpired()) {
            return null;
        }

        $now = new \DateTimeImmutable();
        if (
            $record->lastUsedAt === null
            || ($now->getTimestamp() - $record->lastUsedAt->getTimestamp()) >= self::LAST_USED_DEBOUNCE_SECONDS
        ) {
            try {
                $this->storage->updateLastUsedAt($record->id, $now);
            } catch (\Throwable) {
                // Best-effort — never fail the request for a usage-tracking write
            }
        }

        return $record;
    }

    public function revoke(string $tokenId): void
    {
        $this->storage->revoke($tokenId);
    }

    /**
     * Rotate: revoke the old token and issue a new one for the same tenant/scopes/CIDRs.
     *
     * @return array{raw: string, record: ScimTokenRecord}
     */
    public function rotate(string $oldTokenId, string $tenantId, array $scopes, array $allowedCidrs, ?\DateTimeImmutable $expiresAt = null): array
    {
        $this->storage->revoke($oldTokenId);

        return $this->issue($tenantId, $scopes, $allowedCidrs, $expiresAt);
    }

    /** @return list<ScimTokenRecord> */
    public function listForTenant(string $tenantId): array
    {
        return $this->storage->findByTenantId($tenantId);
    }
}
