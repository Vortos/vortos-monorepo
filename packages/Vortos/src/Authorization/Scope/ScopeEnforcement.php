<?php

declare(strict_types=1);

namespace Vortos\Authorization\Scope;

/**
 * The three — and only three — ways the authorization engine can prove the
 * relationship a permission's scope segment claims.
 *
 * The framework owns these *kinds*. It does not own scope *names*: names like
 * `org`, `team`, `federation`, `tenant` are app concepts mapped onto a kind via
 * app config (see {@see ScopeEnforcementClassifier}). The framework defaults only
 * the universal names it can legitimately claim to understand:
 * `any`/`global` => SelfSufficient, `own` => Ownership.
 */
enum ScopeEnforcement: string
{
    /**
     * RBAC `has()` alone proves it (coarse role->capability). No resource needed.
     * No-policy default: ALLOW (RbacAuthoritative).
     */
    case SelfSufficient = 'self_sufficient';

    /**
     * Membership in a named container, proven by the scoped store (canScoped).
     * No-policy default: ALLOW only if a matching scoped binding was enforced this
     * request (ScopeSatisfied); otherwise a policy is required.
     */
    case Containment = 'containment';

    /**
     * Record-level ownership / state / invariant — only a policy can prove it, never
     * RBAC and never the scoped store.
     * No-policy default: DENY (a policy is mandatory). This is the safe default for
     * any scope the framework cannot classify.
     */
    case Ownership = 'ownership';
}
