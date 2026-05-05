<?php

declare(strict_types=1);

namespace Vortos\Authorization\Contract;

interface RolePermissionStoreInterface
{
    /**
     * @param string[] $roles
     * @return array<string, string[]>
     */
    public function permissionsForRoles(array $roles): array;

    /**
     * @return string[]
     */
    public function permissionsForRole(string $role): array;

    public function grant(string $role, string $permission): void;

    public function revoke(string $role, string $permission): void;
}
