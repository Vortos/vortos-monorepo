<?php

declare(strict_types=1);

namespace Vortos\Authorization\Resolver;

final class RoleGenerationStore
{
    private const KEY_PREFIX = 'authorization:role_gen:';
    private const TTL = 86_400; // 24 hours — resets on each increment

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

        // Batch-fetch all role generation counters in a single pipeline round-trip
        $this->redis->multi(\Redis::PIPELINE);
        foreach ($roles as $role) {
            $this->redis->get(self::KEY_PREFIX . $role);
        }
        $values = $this->redis->exec();

        $generations = [];
        foreach ($roles as $i => $role) {
            $value = $values[$i] ?? false;
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
        $script = <<<'LUA'
local v = redis.call("incr", KEYS[1])
redis.call("expire", KEYS[1], ARGV[1])
return v
LUA;
        $this->redis->eval($script, [self::KEY_PREFIX . $role, self::TTL], 1);
    }
}
