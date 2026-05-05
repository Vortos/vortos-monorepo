<?php

declare(strict_types=1);

namespace Vortos\Authorization\Scope\Storage;

use Vortos\Authorization\Scope\Contract\ScopedPermissionStoreInterface;

final class NullScopedPermissionStore implements ScopedPermissionStoreInterface
{
    public function grant(
        string $userId,
        string $scopeName,
        string $scopeId,
        string $permission,
        ?\DateTimeImmutable $expiresAt = null,
    ): void {
    }

    public function revoke(
        string $userId,
        string $scopeName,
        string $scopeId,
        string $permission,
    ): void {
    }

    public function has(
        string $userId,
        string $scopeName,
        string $scopeId,
        string $permission,
    ): bool {
        return false;
    }

    public function revokeAll(string $userId, string $scopeName, string $scopeId): void
    {
    }
}
