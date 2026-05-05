<?php

declare(strict_types=1);

namespace Vortos\Authorization\Contract;

interface UserRoleStoreInterface
{
    /**
     * @return string[]
     */
    public function rolesForUser(string $userId): array;

    public function assignRole(string $userId, string $role): void;

    public function removeRole(string $userId, string $role): void;

    /**
     * @return string[]
     */
    public function usersForRole(string $role, int $limit, int $offset): array;

    /**
     * @param string[] $userIds
     * @return array<string, string[]>
     */
    public function rolesForUsers(array $userIds): array;
}
