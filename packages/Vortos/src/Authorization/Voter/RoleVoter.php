<?php

declare(strict_types=1);

namespace Vortos\Authorization\Voter;

use Vortos\Auth\Contract\UserIdentityInterface;

/**
 * Simple role-based voter for policies that do not need resource loading.
 *
 * Use this in policies for actions where the decision is purely role-based
 * with no resource-level scope check.
 *
 * ## Usage inside a policy
 *
 *   final class CompetitionPolicy implements PolicyInterface
 *   {
 *       public function __construct(private RoleVoter $roles) {}
 *
 *       public function can(UserIdentityInterface $identity, string $action, string $scope, mixed $resource = null): bool
 *       {
 *           return match ($action) {
 *               'list'   => $this->roles->atLeast($identity, 'ROLE_USER'),
 *               'read'   => $this->roles->atLeast($identity, 'ROLE_USER'),
 *               'create' => $this->roles->atLeast($identity, 'ROLE_FEDERATION_ADMIN'),
 *               'update' => $this->roles->atLeast($identity, 'ROLE_FEDERATION_ADMIN'),
 *               'delete' => $this->roles->hasAny($identity, ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']),
 *               default  => false,
 *           };
 *       }
 *   }
 *
 * ## Role hierarchy
 *
 * Define a role hierarchy in config/authorization.php:
 *
 *   $config->roleHierarchy([
 *       'ROLE_SUPER_ADMIN'      => ['ROLE_ADMIN'],
 *       'ROLE_ADMIN'            => ['ROLE_FEDERATION_ADMIN'],
 *       'ROLE_FEDERATION_ADMIN' => ['ROLE_COACH', 'ROLE_JUDGE'],
 *       'ROLE_COACH'            => ['ROLE_USER'],
 *       'ROLE_JUDGE'            => ['ROLE_USER'],
 *   ]);
 *
 * With this hierarchy, hasRole('ROLE_ADMIN') returns true for ROLE_SUPER_ADMIN.
 */
final class RoleVoter
{
    /**
     * @param array<string, string[]> $hierarchy Role → inherited roles
     */
    public function __construct(private array $hierarchy = []) {}

    /**
     * Check if identity has the exact role or inherits it via hierarchy.
     */
    public function hasRole(UserIdentityInterface $identity, string $role): bool
    {
        return in_array($role, $this->expandRoles($identity->roles()), true);
    }

    /**
     * Check if identity has at least the given role (via hierarchy).
     * Alias for hasRole — more readable in policy methods.
     */
    public function atLeast(UserIdentityInterface $identity, string $minimumRole): bool
    {
        return $this->hasRole($identity, $minimumRole);
    }

    /**
     * Check if identity has any of the given roles.
     *
     * @param string[] $roles
     */
    public function hasAny(UserIdentityInterface $identity, array $roles): bool
    {
        $expanded = $this->expandRoles($identity->roles());
        return !empty(array_intersect($roles, $expanded));
    }

    /**
     * Check if identity has all of the given roles.
     *
     * @param string[] $roles
     */
    public function hasAll(UserIdentityInterface $identity, array $roles): bool
    {
        $expanded = $this->expandRoles($identity->roles());
        return empty(array_diff($roles, $expanded));
    }

    /**
     * Expand a set of roles to include all inherited roles via the hierarchy.
     *
     * @param  string[] $roles
     * @return string[]
     */
    private function expandRoles(array $roles): array
    {
        $expanded = $roles;
        $queue = $roles;

        while (!empty($queue)) {
            $role = array_shift($queue);
            $inherited = $this->hierarchy[$role] ?? [];

            foreach ($inherited as $inheritedRole) {
                if (!in_array($inheritedRole, $expanded, true)) {
                    $expanded[] = $inheritedRole;
                    $queue[] = $inheritedRole;
                }
            }
        }

        return $expanded;
    }
}
