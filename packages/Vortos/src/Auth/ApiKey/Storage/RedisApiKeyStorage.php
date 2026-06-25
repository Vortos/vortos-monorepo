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
 *
 * ## Atomicity
 *
 * Revocation uses a Lua script to atomically delete the hash entry, id entry,
 * and remove from the user set — no crash can leave orphaned credentials.
 */
final class RedisApiKeyStorage implements ApiKeyStorageInterface
{
    private const LUA_REVOKE = <<<'LUA'
local idKey = KEYS[1]
local hash = redis.call('GET', idKey)
if not hash then
    redis.call('DEL', idKey)
    return 0
end
local data = redis.call('GET', 'apikey:hash:' .. hash)
local userId = false
if data then
    local decoded = cjson.decode(data)
    userId = decoded and decoded['user_id']
end
redis.call('DEL', 'apikey:hash:' .. hash)
redis.call('DEL', idKey)
if userId then
    redis.call('SREM', 'apikey:user:' .. userId, ARGV[1])
end
return 1
LUA;

    private const LUA_TOUCH_LAST_USED = <<<'LUA'
local key = KEYS[1]
local data = redis.call('GET', key)
if not data then
    return 0
end
local record = cjson.decode(data)
local lastUsed = record['last_used_at']
local now = ARGV[1]
local debounce = tonumber(ARGV[2])
if lastUsed then
    local lastTs = tonumber(ARGV[3])
    if lastTs and (tonumber(ARGV[4]) - lastTs) < debounce then
        return 0
    end
end
record['last_used_at'] = now
local ttl = redis.call('TTL', key)
if ttl > 0 then
    redis.call('SETEX', key, ttl, cjson.encode(record))
else
    redis.call('SET', key, cjson.encode(record))
end
return 1
LUA;

    public function __construct(private readonly \Redis $redis) {}

    public function findByHash(string $hashedKey): ?ApiKeyRecord
    {
        $json = $this->redis->get('apikey:hash:' . $hashedKey);
        if ($json === false || $json === null) {
            return null;
        }

        return $this->deserialize(json_decode((string) $json, true, 512));
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
        $this->redis->eval(
            self::LUA_REVOKE,
            ['apikey:id:' . $keyId, $keyId],
            1,
        );
    }

    public function touchLastUsedAt(string $hashedKey, \DateTimeImmutable $at): void
    {
        $record = $this->findByHash($hashedKey);
        $lastTs = $record?->lastUsedAt?->getTimestamp() ?? 0;

        $this->redis->eval(
            self::LUA_TOUCH_LAST_USED,
            [
                'apikey:hash:' . $hashedKey,
                $at->format(\DateTimeInterface::ATOM),
                60,
                (string) $lastTs,
                (string) $at->getTimestamp(),
            ],
            1,
        );
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
