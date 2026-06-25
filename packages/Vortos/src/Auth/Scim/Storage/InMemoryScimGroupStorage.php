<?php

declare(strict_types=1);

namespace Vortos\Auth\Scim\Storage;

use Vortos\Auth\Scim\Domain\ScimGroup;

final class InMemoryScimGroupStorage implements ScimGroupStorageInterface
{
    /** @var array<string, ScimGroup> */
    private array $groups = [];

    public function findById(string $tenantId, string $id): ?ScimGroup
    {
        $group = $this->groups[$id] ?? null;

        return $group !== null && $group->tenantId === $tenantId ? $group : null;
    }

    public function findByExternalId(string $tenantId, string $externalId): ?ScimGroup
    {
        foreach ($this->groups as $group) {
            if ($group->tenantId === $tenantId && $group->externalId === $externalId) {
                return $group;
            }
        }

        return null;
    }

    public function list(string $tenantId, ?string $filter = null, int $startIndex = 1, int $count = 100): array
    {
        $all = array_values(array_filter(
            $this->groups,
            static fn(ScimGroup $g) => $g->tenantId === $tenantId,
        ));

        if ($filter !== null && preg_match('/^displayName\s+eq\s+"?([^"]*)"?$/i', trim($filter), $m)) {
            $all = array_values(array_filter(
                $all,
                static fn(ScimGroup $g) => strtolower($g->displayName) === strtolower($m[1]),
            ));
        }

        $total = count($all);
        $page  = array_slice($all, max(0, $startIndex - 1), $count);

        return ['resources' => $page, 'totalResults' => $total];
    }

    public function save(ScimGroup $group): void
    {
        $this->groups[$group->id] = $group;
    }

    public function delete(string $tenantId, string $id): void
    {
        $group = $this->groups[$id] ?? null;
        if ($group !== null && $group->tenantId === $tenantId) {
            unset($this->groups[$id]);
        }
    }
}
