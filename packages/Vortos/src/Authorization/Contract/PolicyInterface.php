<?php

declare(strict_types=1);

namespace Vortos\Authorization\Contract;

use Vortos\Authorization\Context\AuthorizationContext;

/**
 * Contract for resource-specific authorization policies.
 *
 * The resource this policy handles is declared via #[AsPolicy(resource: '...')] —
 * the registry is keyed at compile time, so no supports() method is needed.
 *
 * ## Implementation example
 *
 *   #[AsPolicy(resource: 'athletes')]
 *   final class AthletePolicy implements PolicyInterface
 *   {
 *       public function can(AuthorizationContext $auth, string $action, string $scope, mixed $resource = null): bool {
 *           return match ($action) {
 *               'read'   => true,
 *               'create' => $auth->atLeast('ROLE_COACH'),
 *               default  => false,
 *           };
 *       }
 *   }
 *
 * ## Resource parameter
 *
 * $resource is whatever was fetched via resourceParam on #[RequiresPermission].
 * May be null — always handle null safely.
 */
interface PolicyInterface
{
    /**
     * Evaluate whether the identity is allowed to perform action on resource.
     *
     * @param AuthorizationContext  $auth     Resolved authorization context for the current user
     * @param string                $action   The action (create, read, update, delete, list)
     * @param string                $scope    The scope (any, own, federation, global)
     * @param mixed                 $resource The loaded resource for scope checks, or null
     */
    public function can(
        AuthorizationContext $auth,
        string $action,
        string $scope,
        mixed $resource = null,
    ): bool;
}
