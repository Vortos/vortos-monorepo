<?php

declare(strict_types=1);

namespace Vortos\Authorization\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Authorization\Engine\PolicyEngine;
use Vortos\Authorization\Engine\PolicyRegistry;
use Vortos\Authorization\Permission\PermissionRegistry;
use Vortos\Authorization\Scope\ScopeEnforcement;
use Vortos\Authorization\Scope\ScopeEnforcementClassifier;
use Vortos\Authorization\Storage\NullAuthorizationVersionStore;
use Vortos\Authorization\Storage\NullEmergencyDenyList;
use Vortos\Authorization\Voter\RoleVoter;

/**
 * Exhaustively enumerates the no-policy decision matrix and asserts the safety
 * invariant: an allow happens ONLY when the relationship was actually proven —
 * never for an ownership/unknown scope, and never for containment without an
 * enforced binding. No unintended allow can slip through.
 */
final class DecisionMatrixPropertyTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ScopeEnforcement|null, bool, bool, bool, bool}>
     *   [scopeName, classifyAs(null=unknown), policyRequired, selfEnforced, bindingSupplied, bindingSatisfied]
     */
    public static function matrix(): iterable
    {
        $scopes = [
            'any' => ScopeEnforcement::SelfSufficient,
            'global' => ScopeEnforcement::SelfSufficient,
            'org' => ScopeEnforcement::Containment,
            'own' => ScopeEnforcement::Ownership,
            'federation' => null, // unclassified -> Ownership fail-closed
        ];

        foreach ($scopes as $scopeName => $kind) {
            foreach ([false, true] as $policyRequired) {
                foreach ([false, true] as $selfEnforced) {
                    foreach ([false, true] as $bindingSupplied) {
                        foreach ([false, true] as $bindingSatisfied) {
                            if (!$bindingSupplied && $bindingSatisfied) {
                                continue; // impossible combination
                            }

                            $key = sprintf(
                                '%s|pr=%d|se=%d|sup=%d|sat=%d',
                                $scopeName,
                                $policyRequired,
                                $selfEnforced,
                                $bindingSupplied,
                                $bindingSatisfied,
                            );

                            yield $key => [$scopeName, $kind, $policyRequired, $selfEnforced, $bindingSupplied, $bindingSatisfied];
                        }
                    }
                }
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('matrix')]
    public function test_no_policy_decision_is_safe(
        string $scopeName,
        ?ScopeEnforcement $kind,
        bool $policyRequired,
        bool $selfEnforced,
        bool $bindingSupplied,
        bool $bindingSatisfied,
    ): void {
        $permission = "things.do.$scopeName";
        $roleVoter = new RoleVoter();

        $store = new InMemoryScopedStore();
        if ($bindingSatisfied) {
            $store->grant('u1', $scopeName, 's1', $permission);
        }

        $scopeMap = $kind !== null ? [$scopeName => $kind] : [];

        $engine = new PolicyEngine(
            new PolicyRegistry(new ServiceLocator([])), // never a policy
            new PermissionRegistry([$permission => $this->meta($permission, $policyRequired, $selfEnforced)]),
            new ArrayResolver($roleVoter, [$permission]), // RBAC always grants
            new NullEmergencyDenyList(),
            new NullAuthorizationVersionStore(),
            $roleVoter,
            scopedPermissions: $store,
            scopeClassifier: new ScopeEnforcementClassifier($scopeMap),
        );

        $identity = new UserIdentity('u1', ['ROLE_USER']);

        $decision = $bindingSupplied
            ? $engine->decideScoped($identity, $permission, [$scopeName => 's1'])
            : $engine->decide($identity, $permission);

        // Compute the ONLY conditions under which an allow is permissible.
        $effectiveKind = $kind ?? ScopeEnforcement::Ownership;
        $scopeEnforced = $bindingSupplied && $bindingSatisfied;

        $expectedAllow = match (true) {
            // A supplied-but-unsatisfied binding is denied outright before no-policy logic.
            $bindingSupplied && !$bindingSatisfied => false,
            $policyRequired => false,
            $selfEnforced => true,
            $effectiveKind === ScopeEnforcement::SelfSufficient => true,
            $effectiveKind === ScopeEnforcement::Containment => $scopeEnforced,
            default => false, // Ownership / unknown
        };

        $this->assertSame(
            $expectedAllow,
            $decision->allowed(),
            sprintf('Unexpected decision for %s.do.%s (got reason: %s)', 'things', $scopeName, $decision->reason()),
        );

        // Hard invariant: ownership/unknown scope can NEVER allow without policyRequired/selfEnforced.
        if ($effectiveKind === ScopeEnforcement::Ownership && !$selfEnforced) {
            $this->assertFalse($decision->allowed(), 'Ownership scope must never allow without a policy.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(string $permission, bool $policyRequired, bool $selfEnforced): array
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
