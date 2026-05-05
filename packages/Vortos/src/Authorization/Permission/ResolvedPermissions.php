<?php

declare(strict_types=1);

namespace Vortos\Authorization\Permission;

final class ResolvedPermissions
{
    /** @var array<string, true> */
    private array $index;

    /**
     * @param string[] $roles
     * @param string[] $expandedRoles
     * @param string[] $permissions
     */
    public function __construct(
        private readonly string $userId,
        private readonly array $roles,
        private readonly array $expandedRoles,
        array $permissions,
        private readonly int $temporalGrantCount = 0,
    ) {
        $permissions = array_values(array_unique(array_filter($permissions, 'is_string')));
        sort($permissions);

        $this->index = array_fill_keys($permissions, true);
    }

    public static function empty(string $userId = ''): self
    {
        return new self($userId, [], [], []);
    }

    /**
     * @param array{
     *     userId: string,
     *     roles: string[],
     *     expandedRoles: string[],
     *     permissions: string[],
     *     temporalGrantCount?: int
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['userId'],
            $data['roles'],
            $data['expandedRoles'],
            $data['permissions'],
            $data['temporalGrantCount'] ?? 0,
        );
    }

    public function has(string $permission): bool
    {
        return isset($this->index[$permission]);
    }

    public function userId(): string
    {
        return $this->userId;
    }

    /**
     * @return string[]
     */
    public function roles(): array
    {
        return $this->roles;
    }

    /**
     * @return string[]
     */
    public function expandedRoles(): array
    {
        return $this->expandedRoles;
    }

    /**
     * @return string[]
     */
    public function permissions(): array
    {
        return array_keys($this->index);
    }

    public function count(): int
    {
        return count($this->index);
    }

    public function temporalGrantCount(): int
    {
        return $this->temporalGrantCount;
    }

    /**
     * @return array{
     *     userId: string,
     *     roles: string[],
     *     expandedRoles: string[],
     *     permissions: string[],
     *     temporalGrantCount: int
     * }
     */
    public function toArray(): array
    {
        return [
            'userId' => $this->userId,
            'roles' => $this->roles,
            'expandedRoles' => $this->expandedRoles,
            'permissions' => $this->permissions(),
            'temporalGrantCount' => $this->temporalGrantCount,
        ];
    }
}
