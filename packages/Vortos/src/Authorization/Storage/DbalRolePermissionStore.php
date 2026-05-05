<?php

declare(strict_types=1);

namespace Vortos\Authorization\Storage;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Vortos\Authorization\Contract\RolePermissionStoreInterface;

final class DbalRolePermissionStore implements RolePermissionStoreInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function permissionsForRoles(array $roles): array
    {
        $roles = array_values(array_unique(array_filter($roles, 'is_string')));

        if ($roles === []) {
            return [];
        }

        $rows = $this->connection->executeQuery(
            'SELECT role, permission FROM role_permissions WHERE role IN (:roles) ORDER BY role, permission',
            ['roles' => $roles],
            ['roles' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $permissions = [];

        foreach ($rows as $row) {
            $role = (string) $row['role'];
            $permissions[$role][] = (string) $row['permission'];
        }

        foreach ($roles as $role) {
            $permissions[$role] ??= [];
        }

        return $permissions;
    }

    public function permissionsForRole(string $role): array
    {
        return $this->permissionsForRoles([$role])[$role] ?? [];
    }

    public function grant(string $role, string $permission): void
    {
        $this->connection->executeStatement(
            'INSERT INTO role_permissions (role, permission) VALUES (:role, :permission) ON CONFLICT (role, permission) DO NOTHING',
            ['role' => $role, 'permission' => $permission],
        );
    }

    public function revoke(string $role, string $permission): void
    {
        $this->connection->executeStatement(
            'DELETE FROM role_permissions WHERE role = :role AND permission = :permission',
            ['role' => $role, 'permission' => $permission],
        );
    }
}
