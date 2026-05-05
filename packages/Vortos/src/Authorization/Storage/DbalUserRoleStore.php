<?php

declare(strict_types=1);

namespace Vortos\Authorization\Storage;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Vortos\Authorization\Contract\UserRoleStoreInterface;

final class DbalUserRoleStore implements UserRoleStoreInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function rolesForUser(string $userId): array
    {
        return array_map(
            'strval',
            $this->connection->executeQuery(
                'SELECT role FROM user_roles WHERE user_id = :userId ORDER BY role',
                ['userId' => $userId],
            )->fetchFirstColumn(),
        );
    }

    public function assignRole(string $userId, string $role): void
    {
        $this->connection->executeStatement(
            'INSERT INTO user_roles (user_id, role) VALUES (:userId, :role) ON CONFLICT (user_id, role) DO NOTHING',
            ['userId' => $userId, 'role' => $role],
        );
    }

    public function removeRole(string $userId, string $role): void
    {
        $this->connection->executeStatement(
            'DELETE FROM user_roles WHERE user_id = :userId AND role = :role',
            ['userId' => $userId, 'role' => $role],
        );
    }

    public function usersForRole(string $role, int $limit, int $offset): array
    {
        return array_map(
            'strval',
            $this->connection->executeQuery(
                'SELECT user_id FROM user_roles WHERE role = :role ORDER BY user_id LIMIT :limit OFFSET :offset',
                ['role' => $role, 'limit' => max(1, $limit), 'offset' => max(0, $offset)],
                ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
            )->fetchFirstColumn(),
        );
    }

    public function rolesForUsers(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter($userIds, 'is_string')));

        if ($userIds === []) {
            return [];
        }

        $rows = $this->connection->executeQuery(
            'SELECT user_id, role FROM user_roles WHERE user_id IN (:userIds) ORDER BY user_id, role',
            ['userIds' => $userIds],
            ['userIds' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $roles = [];

        foreach ($rows as $row) {
            $userId = (string) $row['user_id'];
            $roles[$userId][] = (string) $row['role'];
        }

        foreach ($userIds as $userId) {
            $roles[$userId] ??= [];
        }

        return $roles;
    }
}
