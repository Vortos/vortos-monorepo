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
        $key = $identity->isAuthenticated() ? $identity->id() : '__anonymous__';

        return $this->memo[$key] ??= $this->inner->resolve($identity);
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
