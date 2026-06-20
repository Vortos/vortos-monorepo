<?php

declare(strict_types=1);

namespace Vortos\Authorization\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Authorization\Context\AuthorizationContext;
use Vortos\Authorization\Contract\PolicyInterface;
use Vortos\Authorization\Decision\AuthorizationDecisionReason;
use Vortos\Authorization\Engine\PolicyEngine;
use Vortos\Authorization\Engine\PolicyRegistry;
use Vortos\Authorization\Permission\PermissionRegistry;
use Vortos\Authorization\Permission\ResolvedPermissions;
use Vortos\Authorization\Contract\PermissionResolverInterface;
use Vortos\Authorization\Scope\Contract\ScopedPermissionStoreInterface;
use Vortos\Authorization\Scope\ScopeEnforcement;
use Vortos\Authorization\Scope\ScopeEnforcementClassifier;
use Vortos\Authorization\Storage\NullAuthorizationVersionStore;
use Vortos\Authorization\Storage\NullEmergencyDenyList;
use Vortos\Authorization\Voter\RoleVoter;

/**
 * The Phase 2 security matrix: the scope-aware no-policy decision, including the
 * ownership-vs-containment privilege-escalation guard.
 */
final class ScopeAwareDecisionTest extends TestCase
{
    private RoleVoter $roleVoter;

    protected function setUp(): void
    {
        $this->roleVoter = new RoleVoter([]);
    }

    public function test_self_sufficient_scope_no_policy_allows_rbac_authoritative(): void
    {
        $decision = $this->engine(['reports.view.any'])
            ->decide($this->user(['reports.view.any']), 'reports.view.any');

        $this->assertTrue($decision->allowed());
        $this->assertSame(AuthorizationDecisionReason::RbacAuthoritative->value, $decision->reason());
    }

    public function test_ownership_scope_no_policy_no_binding_denies(): void
    {
        $decision = $this->engine(['forms.edit.own'])
            ->decide($this->user(['forms.edit.own']), 'forms.edit.own');

        $this->assertFalse($decision->allowed());
        $this->assertSame(AuthorizationDecisionReason::PolicyOrScopeRequired->value, $decision->reason());
    }

    /**
     * THE PRIVESC GUARD: an ownership permission invoked WITH a satisfied containment
     * binding must still be denied — containment never proves record ownership.
     */
    public function test_ownership_scope_no_policy_with_satisfied_containment_binding_still_denies(): void
    {
        $store = new InMemoryScopedStore();
        $store->grant('u1', 'org', 'org-1', 'forms.edit.own');

        $decision = $this->engine(['forms.edit.own'], $store, ['org' => ScopeEnforcement::Containment])
            ->decideScoped($this->user(['forms.edit.own']), 'forms.edit.own', ['org' => 'org-1']);

        $this->assertFalse($decision->allowed());
        $this->assertSame(AuthorizationDecisionReason::PolicyOrScopeRequired->value, $decision->reason());
    }

    public function test_containment_scope_no_policy_with_satisfied_binding_allows_scope_satisfied(): void
    {
        $store = new InMemoryScopedStore();
        $store->grant('u1', 'org', 'org-1', 'documents.edit.org');

        $decision = $this->engine(['documents.edit.org'], $store, ['org' => ScopeEnforcement::Containment])
            ->decideScoped($this->user(['documents.edit.org']), 'documents.edit.org', ['org' => 'org-1']);

        $this->assertTrue($decision->allowed());
        $this->assertSame(AuthorizationDecisionReason::ScopeSatisfied->value, $decision->reason());
    }

    public function test_containment_scope_no_policy_without_binding_denies(): void
    {
        // No scopes supplied at all -> containment relationship unenforced.
        $decision = $this->engine(['documents.edit.org'], null, ['org' => ScopeEnforcement::Containment])
            ->decide($this->user(['documents.edit.org']), 'documents.edit.org');

        $this->assertFalse($decision->allowed());
        $this->assertSame(AuthorizationDecisionReason::PolicyOrScopeRequired->value, $decision->reason());
    }

    public function test_containment_scope_with_missing_binding_denies_scoped_permission(): void
    {
        // Scopes supplied but the scoped store has no grant -> denied before no-policy logic.
        $decision = $this->engine(['documents.edit.org'], new InMemoryScopedStore(), ['org' => ScopeEnforcement::Containment])
            ->decideScoped($this->user(['documents.edit.org']), 'documents.edit.org', ['org' => 'org-1']);

        $this->assertFalse($decision->allowed());
        $this->assertSame(AuthorizationDecisionReason::ScopedPermissionDenied->value, $decision->reason());
    }

    public function test_unknown_scope_no_policy_fails_closed(): void
    {
        // 'federation' is not classified -> Ownership default -> deny.
        $decision = $this->engine(['ledgers.read.federation'])
            ->decide($this->user(['ledgers.read.federation']), 'ledgers.read.federation');

        $this->assertFalse($decision->allowed());
        $this->assertSame(AuthorizationDecisionReason::PolicyOrScopeRequired->value, $decision->reason());
    }

    public function test_policy_required_flag_denies_self_sufficient_scope_without_policy(): void
    {
        $perms = ['secrets.read.any' => $this->meta('secrets.read.any', policyRequired: true)];

        $decision = $this->engineFromMeta($perms)
            ->decide($this->user(['secrets.read.any']), 'secrets.read.any');

        $this->assertFalse($decision->allowed());
        $this->assertSame(AuthorizationDecisionReason::PolicyRequired->value, $decision->reason());
    }

    public function test_self_enforced_flag_allows_without_policy_as_externally_enforced(): void
    {
        $perms = ['payments.review.org' => $this->meta('payments.review.org', selfEnforced: true)];

        $decision = $this->engineFromMeta($perms)
            ->decide($this->user(['payments.review.org']), 'payments.review.org');

        $this->assertTrue($decision->allowed());
        $this->assertSame(AuthorizationDecisionReason::ExternallyEnforced->value, $decision->reason());
    }

    public function test_policy_required_takes_precedence_over_self_enforced(): void
    {
        $perms = ['both.do.any' => $this->meta('both.do.any', policyRequired: true, selfEnforced: true)];

        $decision = $this->engineFromMeta($perms)
            ->decide($this->user(['both.do.any']), 'both.do.any');

        $this->assertFalse($decision->allowed());
        $this->assertSame(AuthorizationDecisionReason::PolicyRequired->value, $decision->reason());
    }

    public function test_registered_policy_still_runs_and_can_restrict(): void
    {
        $registry = new PolicyRegistry(new ServiceLocator([
            'widgets' => fn() => new DenyAllPolicy(),
        ]));

        $engine = new PolicyEngine(
            $registry,
            new PermissionRegistry(['widgets.read.any' => $this->meta('widgets.read.any')]),
            new ArrayResolver($this->roleVoter, ['widgets.read.any']),
            new NullEmergencyDenyList(),
            new NullAuthorizationVersionStore(),
            $this->roleVoter,
        );

        $decision = $engine->decide($this->user(['widgets.read.any']), 'widgets.read.any');

        // RBAC grants it, but the policy denies -> ResourceDenied (policy may restrict).
        $this->assertFalse($decision->allowed());
        $this->assertSame(AuthorizationDecisionReason::ResourceDenied->value, $decision->reason());
    }

    public function test_policy_cannot_grant_permission_rbac_denies(): void
    {
        $registry = new PolicyRegistry(new ServiceLocator([
            'widgets' => fn() => new AllowAllPolicy(),
        ]));

        $engine = new PolicyEngine(
            $registry,
            new PermissionRegistry(['widgets.read.any' => $this->meta('widgets.read.any')]),
            new ArrayResolver($this->roleVoter, []), // RBAC grants nothing
            new NullEmergencyDenyList(),
            new NullAuthorizationVersionStore(),
            $this->roleVoter,
        );

        $decision = $engine->decide($this->user(['widgets.read.any']), 'widgets.read.any');

        // Even an allow-all policy can't re-authorize: has() failed first.
        $this->assertFalse($decision->allowed());
        $this->assertSame(AuthorizationDecisionReason::MissingPermission->value, $decision->reason());
    }

    // --- helpers ---

    /**
     * @param string[] $permissions permission strings to register + grant to ROLE_USER
     * @param array<string, ScopeEnforcement> $scopeMap
     */
    private function engine(
        array $permissions,
        ?ScopedPermissionStoreInterface $store = null,
        array $scopeMap = [],
    ): PolicyEngine {
        $meta = [];
        foreach ($permissions as $p) {
            $meta[$p] = $this->meta($p);
        }

        return $this->engineFromMeta($meta, $store, $scopeMap);
    }

    /**
     * @param array<string, array<string, mixed>> $meta
     * @param array<string, ScopeEnforcement> $scopeMap
     */
    private function engineFromMeta(
        array $meta,
        ?ScopedPermissionStoreInterface $store = null,
        array $scopeMap = [],
    ): PolicyEngine {
        return new PolicyEngine(
            new PolicyRegistry(new ServiceLocator([])), // no policies registered
            new PermissionRegistry($meta),
            new ArrayResolver($this->roleVoter, array_keys($meta)),
            new NullEmergencyDenyList(),
            new NullAuthorizationVersionStore(),
            $this->roleVoter,
            scopedPermissions: $store,
            scopeClassifier: new ScopeEnforcementClassifier($scopeMap),
        );
    }

    /**
     * @param string[] $permissions
     */
    private function user(array $permissions): UserIdentity
    {
        return new UserIdentity('u1', ['ROLE_USER']);
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(string $permission, bool $policyRequired = false, bool $selfEnforced = false): array
    {
        [$resource, $action, $scope] = explode('.', $permission);

        return [
            'permission' => $permission,
            'resource' => $resource,
            'action' => $action,
            'scope' => $scope,
            'label' => $permission,
            'description' => null,
            'dangerous' => false,
            'bypassable' => false,
            'policyRequired' => $policyRequired,
            'selfEnforced' => $selfEnforced,
            'group' => $resource,
            'catalogClass' => self::class,
        ];
    }
}

final class ArrayResolver implements PermissionResolverInterface
{
    /**
     * @param string[] $permissions
     */
    public function __construct(
        private readonly RoleVoter $roleVoter,
        private readonly array $permissions,
    ) {
    }

    public function resolve(UserIdentityInterface $identity): ResolvedPermissions
    {
        if (!$identity->isAuthenticated()) {
            return ResolvedPermissions::empty();
        }

        $expanded = $this->roleVoter->expandRoleNames($identity->roles());

        return new ResolvedPermissions($identity->id(), $identity->roles(), $expanded, $this->permissions);
    }

    public function has(UserIdentityInterface $identity, string $permission): bool
    {
        return $this->resolve($identity)->has($permission);
    }
}

final class InMemoryScopedStore implements ScopedPermissionStoreInterface
{
    /** @var array<string, true> */
    private array $grants = [];

    public function grant(string $userId, string $scopeName, string $scopeId, string $permission, ?\DateTimeImmutable $expiresAt = null): void
    {
        $this->grants[$this->key($userId, $scopeName, $scopeId, $permission)] = true;
    }

    public function revoke(string $userId, string $scopeName, string $scopeId, string $permission): void
    {
        unset($this->grants[$this->key($userId, $scopeName, $scopeId, $permission)]);
    }

    public function has(string $userId, string $scopeName, string $scopeId, string $permission): bool
    {
        return isset($this->grants[$this->key($userId, $scopeName, $scopeId, $permission)]);
    }

    public function revokeAll(string $userId, string $scopeName, string $scopeId): void
    {
        foreach (array_keys($this->grants) as $k) {
            if (str_starts_with($k, $userId . '|' . $scopeName . '|' . $scopeId . '|')) {
                unset($this->grants[$k]);
            }
        }
    }

    private function key(string $userId, string $scopeName, string $scopeId, string $permission): string
    {
        return $userId . '|' . $scopeName . '|' . $scopeId . '|' . $permission;
    }
}

#[\Vortos\Authorization\Attribute\AsPolicy(resource: 'widgets')]
final class DenyAllPolicy implements PolicyInterface
{
    public function can(AuthorizationContext $auth, string $action, string $scope, mixed $resource = null): bool
    {
        return false;
    }
}

final class AllowAllPolicy implements PolicyInterface
{
    public function can(AuthorizationContext $auth, string $action, string $scope, mixed $resource = null): bool
    {
        return true;
    }
}
