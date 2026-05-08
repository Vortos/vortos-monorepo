<?php

declare(strict_types=1);

namespace Vortos\Authorization\Storage;

use Vortos\Authorization\Contract\RolePermissionStoreInterface;
use Vortos\Authorization\Resolver\RoleGenerationStore;

final class GenerationalRolePermissionStore implements RolePermissionStoreInterface
{
    public function __construct(
        private readonly RolePermissionStoreInterface $inner,
        private readonly RoleGenerationStore $generations,
    ) {
    }

    public function permissionsForRoles(array $roles): array
    {
        return $this->inner->permissionsForRoles($roles);
    }

    public function permissionsForRole(string $role): array
    {
        return $this->inner->permissionsForRole($role);
    }

    public function grant(string $role, string $permission): void
    {
        $this->inner->grant($role, $permission);
        // Always increment — any grant mutates role permissions and must bust the cache.
        // A read-before-write check to skip unchanged grants introduces a TOCTOU race and
        // can silently leave stale caches when two concurrent grants race on the same role.
        $this->generations->increment($role);
    }

    public function revoke(string $role, string $permission): void
    {
        $this->inner->revoke($role, $permission);
        $this->generations->increment($role);
    }
}
