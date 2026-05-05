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
        $before = $this->inner->permissionsForRole($role);
        $this->inner->grant($role, $permission);

        if (!in_array($permission, $before, true)) {
            $this->generations->increment($role);
        }
    }

    public function revoke(string $role, string $permission): void
    {
        $before = $this->inner->permissionsForRole($role);
        $this->inner->revoke($role, $permission);

        if (in_array($permission, $before, true)) {
            $this->generations->increment($role);
        }
    }
}
