<?php

declare(strict_types=1);

namespace Vortos\Auth\Scim\Storage;

use Vortos\Auth\Scim\Domain\ScimUser;

/** In-memory implementation for tests and simple deployments. */
final class InMemoryScimUserStorage implements ScimUserStorageInterface
{
    /** @var array<string, ScimUser> */
    private array $users = [];

    public function findById(string $tenantId, string $id): ?ScimUser
    {
        $user = $this->users[$id] ?? null;

        return $user !== null && $user->tenantId === $tenantId ? $user : null;
    }

    public function findByExternalId(string $tenantId, string $externalId): ?ScimUser
    {
        foreach ($this->users as $user) {
            if ($user->tenantId === $tenantId && $user->externalId === $externalId) {
                return $user;
            }
        }

        return null;
    }

    public function findByUserName(string $tenantId, string $userName): ?ScimUser
    {
        foreach ($this->users as $user) {
            if ($user->tenantId === $tenantId && strtolower($user->userName) === strtolower($userName)) {
                return $user;
            }
        }

        return null;
    }

    public function list(string $tenantId, ?string $filter = null, int $startIndex = 1, int $count = 100): array
    {
        $all = array_values(array_filter(
            $this->users,
            static fn(ScimUser $u) => $u->tenantId === $tenantId,
        ));

        if ($filter !== null) {
            $all = $this->applyFilter($all, $filter);
        }

        $total  = count($all);
        $offset = max(0, $startIndex - 1);
        $page   = array_slice($all, $offset, $count);

        return ['resources' => $page, 'totalResults' => $total];
    }

    public function save(ScimUser $user): void
    {
        $this->users[$user->id] = $user;
    }

    public function delete(string $tenantId, string $id): void
    {
        $user = $this->users[$id] ?? null;
        if ($user !== null && $user->tenantId === $tenantId) {
            unset($this->users[$id]);
        }
    }

    /** @param ScimUser[] $users */
    private function applyFilter(array $users, string $filter): array
    {
        if (preg_match('/^(\w+)\s+eq\s+"?([^"]*)"?$/', trim($filter), $m)) {
            $attr  = strtolower($m[1]);
            $value = $m[2];

            return array_values(array_filter($users, static function (ScimUser $u) use ($attr, $value): bool {
                return match ($attr) {
                    'username'    => strtolower($u->userName) === strtolower($value),
                    'active'      => ($u->active ? 'true' : 'false') === strtolower($value),
                    'externalid'  => $u->externalId === $value,
                    'displayname' => strtolower($u->displayName) === strtolower($value),
                    default       => false,
                };
            }));
        }

        return $users;
    }
}
