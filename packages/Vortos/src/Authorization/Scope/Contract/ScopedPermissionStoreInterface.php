<?php
declare(strict_types=1);

namespace Vortos\Authorization\Scope\Contract;

/**
 * Stores and retrieves scoped permissions.
 *
 * Critical data — DB primary + Redis cache.
 * Default implementation uses Redis.
 * Replace with DbScopedPermissionStore for persistence guarantees.
 */
interface ScopedPermissionStoreInterface
{
    public function grant(
        string $userId,
        string $scopeName,
        string $scopeId,
        string $permission,
        ?\DateTimeImmutable $expiresAt = null,
    ): void;

    public function revoke(
        string $userId,
        string $scopeName,
        string $scopeId,
        string $permission,
    ): void;

    public function has(
        string $userId,
        string $scopeName,
        string $scopeId,
        string $permission,
    ): bool;

    public function revokeAll(string $userId, string $scopeName, string $scopeId): void;
}
