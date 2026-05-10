<?php

declare(strict_types=1);

namespace Vortos\Auth\ApiKey\Storage;

use Vortos\Auth\ApiKey\ApiKeyRecord;

/**
 * Redis-backed API key storage for fast O(1) lookups.
 *
 * ## Key structure
 *
 *   apikey:hash:{sha256_hex}   → JSON-encoded ApiKeyRecord  (lookup by key)
 *   apikey:user:{userId}       → SET of key IDs            (list by user)
 *   apikey:id:{keyId}          → {sha256_hex}              (reverse lookup for revocation)
 */
final class RedisApiKeyStorage implements ApiKeyStorageInterface
{
    public function __construct(private readonly \Redis $redis) {}

    public function findByHash(string $hashedKey): ?ApiKeyRecord
    {
        $json = $this->redis->get('apikey:hash:' . $hashedKey);
        if ($json === false || $json === null) {
            return null;
        }

        return $this->deserialize(json_decode((string) $json, true));
    }

    public function save(ApiKeyRecord $record): void
    {
        $json = json_encode($this->serialize($record));

        $ttl = $record->expiresAt !== null
            ? $record->expiresAt->getTimestamp() - time()
            : 0;

        if ($ttl > 0) {
            $this->redis->setex('apikey:hash:' . $record->hashedKey, $ttl, $json);
        } else {
            $this->redis->set('apikey:hash:' . $record->hashedKey, $json);
        }

        $this->redis->sadd('apikey:user:' . $record->userId, $record->id);
        $this->redis->set('apikey:id:' . $record->id, $record->hashedKey);
    }

    public function revoke(string $keyId): void
    {
        $hash = $this->redis->get('apikey:id:' . $keyId);
        if ($hash) {
            $this->redis->del('apikey:hash:' . $hash);
        }
        $this->redis->del('apikey:id:' . $keyId);
    }

    public function findByUserId(string $userId): array
    {
        $ids    = $this->redis->smembers('apikey:user:' . $userId) ?: [];
        $result = [];

        foreach ($ids as $id) {
            $hash = $this->redis->get('apikey:id:' . $id);
            if (!$hash) {
                continue;
            }
            $record = $this->findByHash((string) $hash);
            if ($record !== null && $record->active) {
                $result[] = $record;
            }
        }

        return $result;
    }

    private function serialize(ApiKeyRecord $record): array
    {
        return [
            'id'           => $record->id,
            'user_id'      => $record->userId,
            'name'         => $record->name,
            'hashed_key'   => $record->hashedKey,
            'scopes'       => $record->scopes,
            'active'       => $record->active,
            'created_at'   => $record->createdAt->format(\DateTimeInterface::ATOM),
            'expires_at'   => $record->expiresAt?->format(\DateTimeInterface::ATOM),
            'last_used_at' => $record->lastUsedAt?->format(\DateTimeInterface::ATOM),
        ];
    }

    private function deserialize(array $data): ApiKeyRecord
    {
        return new ApiKeyRecord(
            id:          $data['id'],
            userId:      $data['user_id'],
            name:        $data['name'],
            hashedKey:   $data['hashed_key'],
            scopes:      $data['scopes'],
            active:      $data['active'],
            createdAt:   new \DateTimeImmutable($data['created_at']),
            expiresAt:   isset($data['expires_at']) ? new \DateTimeImmutable($data['expires_at']) : null,
            lastUsedAt:  isset($data['last_used_at']) ? new \DateTimeImmutable($data['last_used_at']) : null,
        );
    }
}
