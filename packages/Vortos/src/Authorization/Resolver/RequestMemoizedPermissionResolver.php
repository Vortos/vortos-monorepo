<?php

declare(strict_types=1);

namespace Vortos\Authorization\Resolver;

use Symfony\Contracts\Service\ResetInterface;
use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Authorization\Contract\PermissionResolverInterface;
use Vortos\Authorization\Permission\ResolvedPermissions;

final class RequestMemoizedPermissionResolver implements PermissionResolverInterface, ResetInterface
{
    /** @var array<string, ResolvedPermissions> */
    private array $memo = [];

    public function __construct(private readonly PermissionResolverInterface $inner)
    {
    }

    public function resolve(UserIdentityInterface $identity): ResolvedPermissions
    {
        return $this->memo[$this->memoKey($identity)] ??= $this->inner->resolve($identity);
    }

    /**
     * Keyed on the roles as well as the user, for the same reason the Redis layer is:
     * resolution reads the roles the identity presents, so the same person holding a
     * different role set is a different question. This process outlives the request
     * under a worker runtime, which is exactly where answering it from the wrong
     * memo entry would be hardest to see.
     */
    private function memoKey(UserIdentityInterface $identity): string
    {
        if (!$identity->isAuthenticated()) {
            return '__anonymous__';
        }

        $roles = array_values(array_unique(array_filter($identity->roles(), 'is_string')));
        sort($roles);

        return $identity->id() . '|' . hash('sha256', json_encode($roles, JSON_THROW_ON_ERROR));
    }

    public function has(UserIdentityInterface $identity, string $permission): bool
    {
        return $this->resolve($identity)->has($permission);
    }

    public function reset(): void
    {
        $this->memo = [];
    }
}
