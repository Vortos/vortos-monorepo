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

        $this->redis->setEx(
            $this->key($userId, $permission),
            $ttl,
            json_encode(['expires_at' => $expiresAt->getTimestamp()]),
        );
    }

    public function revoke(string $userId, string $permission): void
    {
        $this->redis->del($this->key($userId, $permission));
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

    private function key(string $userId, string $permission): string
    {
        return "temporal_perm:{$userId}:{$permission}";
    }
}
