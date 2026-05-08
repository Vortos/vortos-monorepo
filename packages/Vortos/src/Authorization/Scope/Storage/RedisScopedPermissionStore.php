<?php
declare(strict_types=1);

namespace Vortos\Authorization\Scope\Storage;

use Vortos\Authorization\Scope\Contract\ScopedPermissionStoreInterface;

/**
 * Redis-backed scoped permission store.
 *
 * Key format: scoped_perm:{scopeName}:{scopeId}:{userId}:{permission}
 *
 * Security: Redis restart = data loss. For critical permissions,
 * use DbScopedPermissionStore as primary and this as cache.
 */
final class RedisScopedPermissionStore implements ScopedPermissionStoreInterface
{
    public function __construct(private \Redis $redis) {}

    public function grant(
        string $userId,
        string $scopeName,
        string $scopeId,
        string $permission,
        ?\DateTimeImmutable $expiresAt = null,
    ): void {
        $key = $this->key($scopeName, $scopeId, $userId, $permission);
        $ttl = $expiresAt ? $expiresAt->getTimestamp() - time() : 0;

        if ($ttl > 0) {
            $this->redis->setEx($key, $ttl, '1');
        } else {
            $this->redis->set($key, '1');
        }
    }

    public function revoke(
        string $userId,
        string $scopeName,
        string $scopeId,
        string $permission,
    ): void {
        $this->redis->del($this->key($scopeName, $scopeId, $userId, $permission));
    }

    public function has(
        string $userId,
        string $scopeName,
        string $scopeId,
        string $permission,
    ): bool {
        return (bool) $this->redis->exists($this->key($scopeName, $scopeId, $userId, $permission));
    }

    public function revokeAll(string $userId, string $scopeName, string $scopeId): void
    {
        $pattern = "scoped_perm:{$scopeName}:{$scopeId}:{$userId}:*";
        $iterator = null;

        do {
            $keys = $this->redis->scan($iterator, $pattern, 100);
            if ($keys !== false && !empty($keys)) {
                $this->redis->del(...$keys);
            }
        } while ($iterator !== 0 && $iterator !== null);
    }

    private function key(string $scopeName, string $scopeId, string $userId, string $permission): string
    {
        return "scoped_perm:{$scopeName}:{$scopeId}:{$userId}:{$permission}";
    }
}
