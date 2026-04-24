<?php

declare(strict_types=1);

namespace Vortos\Authorization\Contract;

use Vortos\Auth\Contract\UserIdentityInterface;

/**
 * Contract for resource-specific authorization policies.
 *
 * A policy encapsulates all authorization rules for a single resource type.
 * Each method corresponds to an action in the permission string.
 *
 * ## Naming convention
 *
 * Policy class name: {Resource}Policy — e.g. AthletePolicy, CompetitionPolicy
 * Resource name in permissions: snake_case plural — e.g. 'athletes', 'competitions'
 * Method names match actions: create(), read(), update(), delete(), list()
 *
 * ## Implementation example
 *
 *   #[AsPolicy(resource: 'athletes')]
 *   final class AthletePolicy implements PolicyInterface
 *   {
 *       public function supports(string $resource): bool
 *       {
 *           return $resource === 'athletes';
 *       }
 *
 *       public function can(
 *           UserIdentityInterface $identity,
 *           string $action,
 *           string $scope,
 *           mixed $resource = null,
 *       ): bool {
 *           return match ($action) {
 *               'read'   => $this->canRead($identity, $scope, $resource),
 *               'update' => $this->canUpdate($identity, $scope, $resource),
 *               'delete' => $this->canDelete($identity, $scope, $resource),
 *               'create' => $identity->hasRole('ROLE_COACH'),
 *               'list'   => true, // any authenticated user can list
 *               default  => false,
 *           };
 *       }
 *
 *       private function canUpdate(UserIdentityInterface $identity, string $scope, mixed $resource): bool
 *       {
 *           if ($identity->hasRole('ROLE_ADMIN')) return true;
 *           if ($scope === 'own' && $resource !== null) {
 *               return $resource['athleteId'] === $identity->id();
 *           }
 *           return false;
 *       }
 *   }
 *
 * ## Resource parameter
 *
 * $resource is whatever was fetched using the resourceParam from the route.
 * It may be null if no resourceParam was specified on #[RequiresPermission].
 * Always handle null safely.
 */
interface PolicyInterface
{
    /**
     * Whether this policy handles the given resource type.
     * Called by PolicyRegistry to find the right policy for a permission.
     */
    public function supports(string $resource): bool;

    /**
     * Evaluate whether the identity is allowed to perform action on resource.
     *
     * @param UserIdentityInterface $identity The authenticated user
     * @param string                $action   The action (create, read, update, delete, list)
     * @param string                $scope    The scope (any, own, federation, global)
     * @param mixed                 $resource The loaded resource for scope checks, or null
     *
     * @return bool True if allowed, false if denied
     */
    public function can(
        UserIdentityInterface $identity,
        string $action,
        string $scope,
        mixed $resource = null,
    ): bool;
}
