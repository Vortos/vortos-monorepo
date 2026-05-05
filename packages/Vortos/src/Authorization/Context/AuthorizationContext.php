<?php

declare(strict_types=1);

namespace Vortos\Authorization\Context;

use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Authorization\Permission\ResolvedPermissions;
use Vortos\Authorization\Voter\RoleVoter;

final class AuthorizationContext
{
    public function __construct(
        private readonly UserIdentityInterface $identity,
        private readonly ResolvedPermissions $resolved,
        private readonly RoleVoter $roleVoter,
    ) {
    }

    /**
     * @param string[] $roles
     * @param string[] $permissions
     */
    public static function for(
        string $userId = 'test-user',
        array $roles = [],
        array $permissions = [],
        ?RoleVoter $roleVoter = null,
    ): self {
        $roleVoter ??= new RoleVoter();
        $identity = new UserIdentity($userId, $roles);
        $expandedRoles = $roleVoter->expandRoleNames($roles);

        return new self(
            $identity,
            new ResolvedPermissions($userId, $roles, $expandedRoles, $permissions),
            $roleVoter,
        );
    }

    public function user(): UserIdentityInterface
    {
        return $this->identity;
    }

    public function has(string $permission): bool
    {
        return $this->resolved->has($permission);
    }

    public function atLeast(string $role): bool
    {
        return $this->roleVoter->atLeast($this->identity, $role);
    }

    public function hasRole(string $role): bool
    {
        return $this->roleVoter->hasRole($this->identity, $role);
    }

    /**
     * @param string[] $roles
     */
    public function hasAnyRole(array $roles): bool
    {
        return $this->roleVoter->hasAny($this->identity, $roles);
    }

    public function isSuperAdmin(string $role = 'ROLE_SUPER_ADMIN'): bool
    {
        return $this->roleVoter->hasRole($this->identity, $role);
    }

    public function resolved(): ResolvedPermissions
    {
        return $this->resolved;
    }
}
