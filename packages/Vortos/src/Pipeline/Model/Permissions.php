<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Model;

final readonly class Permissions
{
    /** @var list<Permission> */
    public array $permissions;

    /** @param list<Permission> $permissions */
    public function __construct(array $permissions = [])
    {
        $seen = [];
        foreach ($permissions as $p) {
            if (isset($seen[$p->scope->value])) {
                throw new \InvalidArgumentException(sprintf(
                    'Duplicate permission scope "%s".',
                    $p->scope->value,
                ));
            }
            $seen[$p->scope->value] = true;
        }

        $this->permissions = $permissions;
    }

    public static function readOnly(): self
    {
        return new self([new Permission(PermissionScope::Contents, PermissionAccess::Read)]);
    }

    public function with(Permission $permission): self
    {
        $filtered = array_filter(
            $this->permissions,
            static fn (Permission $p): bool => $p->scope !== $permission->scope,
        );

        return new self([...array_values($filtered), $permission]);
    }

    public function merge(self $other): self
    {
        $merged = $this;
        foreach ($other->permissions as $p) {
            $merged = $merged->with($p);
        }

        return $merged;
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        $result = [];
        foreach ($this->permissions as $p) {
            $result[$p->scope->value] = $p->access->value;
        }

        ksort($result);

        return $result;
    }

    public function isEmpty(): bool
    {
        return $this->permissions === [];
    }
}
