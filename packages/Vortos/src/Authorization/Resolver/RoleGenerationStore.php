<?php

declare(strict_types=1);

namespace Vortos\Authorization\Resolver;

final class RoleGenerationStore
{
    private const KEY = 'authorization:role_generations';

    public function __construct(private readonly \Redis $redis)
    {
    }

    /**
     * @param string[] $roles
     * @return array<string, int>
     */
    public function generationsForRoles(array $roles): array
    {
        $roles = array_values(array_unique(array_filter($roles, 'is_string')));
        sort($roles);

        if ($roles === []) {
            return [];
        }

        $values = $this->redis->hMGet(self::KEY, $roles);
        $generations = [];

        foreach ($roles as $role) {
            $value = is_array($values) ? ($values[$role] ?? false) : false;
            $generations[$role] = $value === false ? 0 : (int) $value;
        }

        return $generations;
    }

    /**
     * @param string[] $roles
     */
    public function hashForRoles(array $roles): string
    {
        return hash('sha256', json_encode($this->generationsForRoles($roles), JSON_THROW_ON_ERROR));
    }

    public function increment(string $role): void
    {
        $this->redis->hIncrBy(self::KEY, $role, 1);
    }
}
