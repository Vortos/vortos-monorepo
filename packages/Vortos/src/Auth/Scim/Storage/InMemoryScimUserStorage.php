<?php

declare(strict_types=1);

namespace Vortos\Auth\Scim\Storage;

use Vortos\Auth\Scim\Domain\ScimUser;

/** In-memory implementation for tests and simple deployments. */
final class InMemoryScimUserStorage implements ScimUserStorageInterface
{
    /** @var array<string, ScimUser> */
    private array $users = [];

    public function findById(string $id): ?ScimUser
    {
        return $this->users[$id] ?? null;
    }

    public function findByExternalId(string $externalId): ?ScimUser
    {
        foreach ($this->users as $user) {
            if ($user->externalId === $externalId) {
                return $user;
            }
        }

        return null;
    }

    public function findByUserName(string $userName): ?ScimUser
    {
        foreach ($this->users as $user) {
            if (strtolower($user->userName) === strtolower($userName)) {
                return $user;
            }
        }

        return null;
    }

    public function list(?string $filter = null, int $startIndex = 1, int $count = 100): array
    {
        $all = array_values($this->users);

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

    public function delete(string $id): void
    {
        unset($this->users[$id]);
    }

    /** @param ScimUser[] $users */
    private function applyFilter(array $users, string $filter): array
    {
        // Simplified SCIM filter: "userName eq "foo"" or "active eq false"
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
