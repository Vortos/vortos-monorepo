<?php
declare(strict_types=1);

namespace Vortos\Authorization\Temporal\Storage;

use Vortos\Authorization\Temporal\Contract\TemporalPermissionStoreInterface;

/**
 * Redis-backed temporal permission store.
 *
 * Key format: temporal_perm:{userId}:{permission}
 * Value: JSON with expiry timestamp
 * TTL: auto-set to match expiry — Redis cleans up automatically
 *
 * Security note: Redis restart = data loss.
 * Use DbTemporalPermissionStore as primary for critical grants.
 */
final class RedisTemporalPermissionStore implements TemporalPermissionStoreInterface
{
    public function __construct(private \Redis $redis) {}

    public function grant(
        string $userId,
        string $permission,
        \DateTimeImmutable $expiresAt,
    ): void {
        $ttl = $expiresAt->getTimestamp() - time();

        if ($ttl <= 0) {
            return; // Already expired — don't store
        }

        $key = $this->key($userId, $permission);
        $indexKey = $this->indexKey($userId);

        // Read current index TTL before entering MULTI so we can decide whether to extend it.
        // -2 = key does not exist, -1 = no TTL (persistent), >= 0 = remaining seconds.
        $currentIndexTtl = $this->redis->ttl($indexKey);

        $this->redis->multi();
        $this->redis->setEx($key, $ttl, json_encode(['expires_at' => $expiresAt->getTimestamp()]));
        $this->redis->sAdd($indexKey, $permission);
        // Only extend the index TTL, never shrink it. Lazy cleanup in activeGrantsForUser handles
        // expired entries left in the index after a short-lived grant outlives the index.
        if ($currentIndexTtl === -2 || ($currentIndexTtl >= 0 && $currentIndexTtl < $ttl)) {
            $this->redis->expire($indexKey, $ttl);
        }
        $this->redis->exec();
    }

    public function revoke(string $userId, string $permission): void
    {
        $this->redis->multi();
        $this->redis->del($this->key($userId, $permission));
        $this->redis->sRem($this->indexKey($userId), $permission);
        $this->redis->exec();
    }

    public function isValid(string $userId, string $permission): bool
    {
        return (bool) $this->redis->exists($this->key($userId, $permission));
    }

    public function getExpiry(string $userId, string $permission): ?\DateTimeImmutable
    {
        $data = $this->redis->get($this->key($userId, $permission));

        if (!$data) return null;

        $payload = json_decode($data, true);
        return isset($payload['expires_at'])
            ? (new \DateTimeImmutable())->setTimestamp($payload['expires_at'])
            : null;
    }

    public function activeGrantsForUser(string $userId): array
    {
        $permissions = $this->redis->sMembers($this->indexKey($userId));

        if ($permissions === [] || $permissions === false) {
            return [];
        }

        $permissions = array_values($permissions);

        // Batch-check existence via pipeline to avoid N sequential round-trips
        $this->redis->multi(\Redis::PIPELINE);
        foreach ($permissions as $permission) {
            $this->redis->exists($this->key($userId, (string) $permission));
        }
        $exists = $this->redis->exec();

        $active = [];
        $toRemove = [];

        foreach ($permissions as $i => $permission) {
            if (!empty($exists[$i])) {
                $active[] = (string) $permission;
            } else {
                $toRemove[] = (string) $permission;
            }
        }

        if (!empty($toRemove)) {
            $this->redis->sRem($this->indexKey($userId), ...$toRemove);
        }

        sort($active);

        return $active;
    }

    private function key(string $userId, string $permission): string
    {
        return "temporal_perm:{$userId}:{$permission}";
    }

    private function indexKey(string $userId): string
    {
        return "temporal_perms:{$userId}";
    }
}
