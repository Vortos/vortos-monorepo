<?php

declare(strict_types=1);

namespace Vortos\Authorization\Contract;

use Vortos\Authorization\Context\AuthorizationContext;
use Vortos\Authorization\Decision\PolicyDecision;

/**
 * Contract for resource-specific authorization policies.
 *
 * The resource this policy handles is declared via #[AsPolicy(resource: '...')] —
 * the registry is keyed at compile time, so no supports() method is needed.
 *
 * ## A policy only REFINES — it never re-authorizes
 *
 * The engine reaches a policy only after RBAC has() has already granted the
 * permission. So a policy must NOT re-check role floors (no atLeast/hasRole) — that
 * is RBAC's job and lives in the #[PermissionCatalog] grants(), the single source of
 * truth for role => capability. A policy earns its place ONLY when it inspects the
 * loaded $resource or $scope for something RBAC cannot express (ownership, entity
 * state, business invariants). If there is no such rule, write no policy — RBAC is
 * authoritative.
 *
 * ## Implementation example (ownership + state, NOT role floors)
 *
 *   #[AsPolicy(resource: 'drafts')]
 *   final class DraftPolicy implements PolicyInterface
 *   {
 *       public function can(AuthorizationContext $auth, string $action, string $scope, mixed $resource = null): bool|PolicyDecision {
 *           if ($auth->scopeIs('own') && !$auth->owns($resource)) {
 *               return PolicyDecision::deny('not_owner');
 *           }
 *           if ($action === 'delete' && $resource?->isPublished()) {
 *               return PolicyDecision::deny('draft_published');
 *           }
 *           return PolicyDecision::allow();
 *       }
 *   }
 *
 * ## Resource parameter
 *
 * $resource is whatever was fetched via resourceParam on #[RequiresPermission].
 * May be null — always handle null safely (e.g. $auth->owns(null) returns false).
 */
interface PolicyInterface
{
    /**
     * Evaluate whether the identity is allowed to perform action on resource.
     *
     * Return a bool (true => allow, false => deny with the generic ResourceDenied reason)
     * or a {@see PolicyDecision} to carry an explainable denial reason. A policy may only
     * RESTRICT — the engine reaches it only after RBAC has already granted the permission.
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
    ): bool|PolicyDecision;
}
