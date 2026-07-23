<?php

declare(strict_types=1);

namespace Vortos\Authorization\Permission;

use Vortos\Authorization\Contract\PermissionRegistryInterface;

/**
 * Expands a granted permission set with everything those grants imply.
 *
 * A catalog declares implications so that holding a capability carries the
 * lesser capabilities it presupposes — `payments.review.any` cannot be
 * meaningfully held without `payments.view.any`. Expansion happens at
 * resolution time and is never persisted: role grant lists stay exactly as an
 * administrator chose them, so introducing a new prerequisite permission does
 * not silently rewrite existing roles, and revoking the capability revokes what
 * it implied along with it.
 *
 * Implications are transitive. A cycle is a catalog authoring error, but it
 * must not hang the resolver, so the closure walk visits each permission once.
 */
final class PermissionImplicationExpander
{
    /** @var array<string, string[]>|null */
    private ?array $closure = null;

    public function __construct(
        private readonly PermissionRegistryInterface $registry,
    ) {
    }

    /**
     * @param string[] $permissions
     * @return string[]
     */
    public function expand(array $permissions): array
    {
        $closure = $this->closure();
        $expanded = [];

        foreach ($permissions as $permission) {
            if (!is_string($permission)) {
                continue;
            }

            $expanded[$permission] = true;

            foreach ($closure[$permission] ?? [] as $implied) {
                $expanded[$implied] = true;
            }
        }

        $result = array_keys($expanded);
        sort($result);

        return $result;
    }

    /**
     * Everything a single permission implies, transitively. Useful for the
     * admin console, which shows an operator what a grant really carries.
     *
     * @return string[]
     */
    public function impliedBy(string $permission): array
    {
        return $this->closure()[$permission] ?? [];
    }

    /**
     * @return array<string, string[]>
     */
    private function closure(): array
    {
        if ($this->closure !== null) {
            return $this->closure;
        }

        $direct = $this->registry->implications();
        $closure = [];

        foreach (array_keys($direct) as $permission) {
            $closure[$permission] = $this->walk($permission, $direct, []);
        }

        return $this->closure = $closure;
    }

    /**
     * @param array<string, string[]> $direct
     * @param array<string, true> $seen
     * @return string[]
     */
    private function walk(string $permission, array $direct, array $seen): array
    {
        if (isset($seen[$permission])) {
            return [];
        }

        $seen[$permission] = true;
        $collected = [];

        foreach ($direct[$permission] ?? [] as $implied) {
            $collected[$implied] = true;

            foreach ($this->walk($implied, $direct, $seen) as $transitive) {
                $collected[$transitive] = true;
            }
        }

        $result = array_keys($collected);
        sort($result);

        return $result;
    }
}
