<?php

declare(strict_types=1);

namespace Vortos\Authorization\Resolver;

use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Authorization\Contract\PermissionResolverInterface;
use Vortos\Authorization\Contract\RolePermissionStoreInterface;
use Vortos\Authorization\Contract\UserRoleStoreInterface;
use Vortos\Authorization\Permission\ResolvedPermissions;
use Vortos\Authorization\Temporal\Contract\TemporalPermissionStoreInterface;
use Vortos\Authorization\Tracing\AuthorizationTracer;
use Vortos\Authorization\Voter\RoleVoter;

final class DatabasePermissionResolver implements PermissionResolverInterface
{
    public function __construct(
        private readonly UserRoleStoreInterface $userRoleStore,
        private readonly RolePermissionStoreInterface $rolePermissionStore,
        private readonly RoleVoter $roleVoter,
        private readonly ?TemporalPermissionStoreInterface $temporalStore = null,
        private readonly ?AuthorizationTracer $tracer = null,
    ) {
    }

    public function resolve(UserIdentityInterface $identity): ResolvedPermissions
    {
        $span = $this->tracer?->resolver('authorization.resolver.database', [
            'authorization.user_id_hash' => $identity->isAuthenticated() ? hash('sha256', $identity->id()) : null,
        ]);

        try {
            $resolved = $this->resolveDatabase($identity);
            $span?->addAttribute('authorization.roles_count', count($resolved->roles()));
            $span?->addAttribute('authorization.expanded_roles_count', count($resolved->expandedRoles()));
            $span?->addAttribute('authorization.permissions_count', count($resolved->permissions()));
            $span?->addAttribute('authorization.temporal_grants_count', $resolved->temporalGrantCount());
            $span?->setStatus('ok');

            return $resolved;
        } catch (\Throwable $e) {
            $span?->recordException($e);
            $span?->setStatus('error');
            throw $e;
        } finally {
            $span?->end();
        }
    }

    private function resolveDatabase(UserIdentityInterface $identity): ResolvedPermissions
    {
        if (!$identity->isAuthenticated()) {
            return ResolvedPermissions::empty();
        }

        $roles = array_values(array_unique(array_merge(
            $identity->roles(),
            $this->userRoleStore->rolesForUser($identity->id()),
        )));
        sort($roles);

        $expandedRoles = $this->roleVoter->expandRoleNames($roles);
        sort($expandedRoles);

        $permissionsByRole = $this->rolePermissionStore->permissionsForRoles($expandedRoles);
        $permissions = [];

        foreach ($permissionsByRole as $rolePermissions) {
            array_push($permissions, ...$rolePermissions);
        }

        $temporalGrants = $this->temporalStore?->activeGrantsForUser($identity->id()) ?? [];
        array_push($permissions, ...$temporalGrants);

        return new ResolvedPermissions(
            $identity->id(),
            $roles,
            $expandedRoles,
            $permissions,
            count($temporalGrants),
        );
    }

    public function has(UserIdentityInterface $identity, string $permission): bool
    {
        return $this->resolve($identity)->has($permission);
    }
}
