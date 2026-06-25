<?php

declare(strict_types=1);

namespace Vortos\Auth\Scim\Token;

/**
 * Redis-backed SCIM token storage.
 *
 * Key structure:
 *   scim:token:hash:{sha256}  → JSON ScimTokenRecord   (lookup by hash)
 *   scim:token:id:{id}        → {sha256}               (reverse lookup for revoke)
 *   scim:token:tenant:{tid}   → SET of token IDs       (list by tenant)
 */
final class RedisScimTokenStorage implements ScimTokenStorageInterface
{
    public function __construct(private readonly \Redis $redis) {}

    public function findByHash(string $hashedToken): ?ScimTokenRecord
    {
        $json = $this->redis->get('scim:token:hash:' . $hashedToken);
        if ($json === false || $json === null) {
            return null;
        }

        $record = $this->deserialize(json_decode((string) $json, true, 512));
        if (!$record->active) {
            return null;
        }

        return $record;
    }

    public function save(ScimTokenRecord $record): void
    {
        $json = json_encode($this->serialize($record));

        $ttl = $record->expiresAt !== null
            ? $record->expiresAt->getTimestamp() - time()
            : 0;

        if ($ttl > 0) {
            $this->redis->setex('scim:token:hash:' . $record->hashedToken, $ttl, $json);
        } else {
            $this->redis->set('scim:token:hash:' . $record->hashedToken, $json);
        }

        $this->redis->set('scim:token:id:' . $record->id, $record->hashedToken);
        $this->redis->sadd('scim:token:tenant:' . $record->tenantId, $record->id);
    }

    public function revoke(string $tokenId): void
    {
        $hash = $this->redis->get('scim:token:id:' . $tokenId);
        if ($hash) {
            $this->redis->del('scim:token:hash:' . $hash);
        }
        $this->redis->del('scim:token:id:' . $tokenId);
    }

    public function findByTenantId(string $tenantId): array
    {
        $ids = $this->redis->smembers('scim:token:tenant:' . $tenantId) ?: [];
        $result = [];

        foreach ($ids as $id) {
            $hash = $this->redis->get('scim:token:id:' . $id);
            if (!$hash) {
                continue;
            }
            $record = $this->findByHash((string) $hash);
            if ($record !== null) {
                $result[] = $record;
            }
        }

        return $result;
    }

    public function updateLastUsedAt(string $tokenId, \DateTimeImmutable $at): void
    {
        $hash = $this->redis->get('scim:token:id:' . $tokenId);
        if (!$hash) {
            return;
        }

        $json = $this->redis->get('scim:token:hash:' . $hash);
        if ($json === false || $json === null) {
            return;
        }

        $data = json_decode((string) $json, true, 512);
        $data['last_used_at'] = $at->format(\DateTimeInterface::ATOM);
        $ttl = $this->redis->ttl('scim:token:hash:' . $hash);

        if ($ttl > 0) {
            $this->redis->setex('scim:token:hash:' . $hash, $ttl, json_encode($data));
        } else {
            $this->redis->set('scim:token:hash:' . $hash, json_encode($data));
        }
    }

    private function serialize(ScimTokenRecord $record): array
    {
        return [
            'id'            => $record->id,
            'tenant_id'     => $record->tenantId,
            'hashed_token'  => $record->hashedToken,
            'scopes'        => $record->scopes,
            'allowed_cidrs' => $record->allowedCidrs,
            'active'        => $record->active,
            'created_at'    => $record->createdAt->format(\DateTimeInterface::ATOM),
            'expires_at'    => $record->expiresAt?->format(\DateTimeInterface::ATOM),
            'last_used_at'  => $record->lastUsedAt?->format(\DateTimeInterface::ATOM),
        ];
    }

    private function deserialize(array $data): ScimTokenRecord
    {
        return new ScimTokenRecord(
            id:           $data['id'],
            tenantId:     $data['tenant_id'],
            hashedToken:  $data['hashed_token'],
            scopes:       $data['scopes'],
            allowedCidrs: $data['allowed_cidrs'] ?? [],
            active:       $data['active'],
            createdAt:    new \DateTimeImmutable($data['created_at']),
            expiresAt:    isset($data['expires_at']) ? new \DateTimeImmutable($data['expires_at']) : null,
            lastUsedAt:   isset($data['last_used_at']) ? new \DateTimeImmutable($data['last_used_at']) : null,
        );
    }
}
