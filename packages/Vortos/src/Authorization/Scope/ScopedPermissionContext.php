<?php
declare(strict_types=1);

namespace Vortos\Authorization\Scope;

use Vortos\Authorization\Scope\Contract\ScopedPermissionStoreInterface;

final class ScopedPermissionContext
{
    public function __construct(
        private ScopedPermissionStoreInterface $store,
        private string $scopeName,
        private string $scopeId,
    ) {}

    public function grant(
        string $userId,
        string|\BackedEnum $permission,
        ?\DateTimeImmutable $expiresAt = null,
    ): void {
        $this->store->grant(
            $userId,
            $this->scopeName,
            $this->scopeId,
            $permission instanceof \BackedEnum ? $permission->value : $permission,
            $expiresAt,
        );
    }

    public function revoke(string $userId, string|\BackedEnum $permission): void
    {
        $this->store->revoke(
            $userId,
            $this->scopeName,
            $this->scopeId,
            $permission instanceof \BackedEnum ? $permission->value : $permission,
        );
    }

    public function has(string $userId, string|\BackedEnum $permission): bool
    {
        return $this->store->has(
            $userId,
            $this->scopeName,
            $this->scopeId,
            $permission instanceof \BackedEnum ? $permission->value : $permission,
        );
    }

    public function revokeAll(string $userId): void
    {
        $this->store->revokeAll($userId, $this->scopeName, $this->scopeId);
    }
}
