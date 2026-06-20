<?php

declare(strict_types=1);

namespace Vortos\Authorization\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Authorization\Context\AuthorizationContext;
use Vortos\Authorization\Contract\PolicyInterface;
use Vortos\Authorization\Decision\AuthorizationDecisionReason;
use Vortos\Authorization\Decision\PolicyDecision;
use Vortos\Authorization\Engine\PolicyEngine;
use Vortos\Authorization\Engine\PolicyRegistry;
use Vortos\Authorization\Ownership\Attribute\Owner;
use Vortos\Authorization\Ownership\Contract\OwnerResolverInterface;
use Vortos\Authorization\Ownership\OwnerResolverRegistry;
use Vortos\Authorization\Permission\PermissionRegistry;
use Vortos\Authorization\Storage\NullAuthorizationVersionStore;
use Vortos\Authorization\Storage\NullEmergencyDenyList;
use Vortos\Authorization\Voter\RoleVoter;

final class OwnershipResolutionTest extends TestCase
{
    public function test_reflective_owner_property_resolves(): void
    {
        $registry = new OwnerResolverRegistry();

        $this->assertSame('user-1', $registry->ownerId(new DraftWithOwnerProperty('user-1')));
    }

    public function test_reflective_owner_getter_resolves(): void
    {
        $registry = new OwnerResolverRegistry();

        $this->assertSame('user-2', $registry->ownerId(new DraftWithOwnerGetter('user-2')));
    }

    public function test_resource_without_owner_marker_returns_null(): void
    {
        $registry = new OwnerResolverRegistry();

        $this->assertNull($registry->ownerId(new \stdClass()));
    }

    public function test_registered_resolver_takes_priority(): void
    {
        $registry = new OwnerResolverRegistry([new StdClassOwnerResolver('owner-x')]);

        $this->assertSame('owner-x', $registry->ownerId(new \stdClass()));
    }

    public function test_int_owner_id_is_normalized_to_string(): void
    {
        $registry = new OwnerResolverRegistry();

        $this->assertSame('42', $registry->ownerId(new DraftWithIntOwner(42)));
    }

    public function test_context_owns_true_for_matching_owner(): void
    {
        $context = new AuthorizationContext(
            new UserIdentity('user-1', []),
            \Vortos\Authorization\Permission\ResolvedPermissions::empty(),
            new RoleVoter(),
            'own',
            new OwnerResolverRegistry(),
        );

        $this->assertTrue($context->owns(new DraftWithOwnerProperty('user-1')));
        $this->assertTrue($context->scopeIs('own'));
    }

    public function test_context_owns_false_for_other_owner_and_null(): void
    {
        $context = new AuthorizationContext(
            new UserIdentity('user-1', []),
            \Vortos\Authorization\Permission\ResolvedPermissions::empty(),
            new RoleVoter(),
            'own',
            new OwnerResolverRegistry(),
        );

        $this->assertFalse($context->owns(new DraftWithOwnerProperty('someone-else')));
        $this->assertFalse($context->owns(null));
        $this->assertFalse($context->owns(new \stdClass()));
    }

    public function test_context_owns_false_without_resolver(): void
    {
        $context = AuthorizationContext::for('user-1');

        $this->assertFalse($context->owns(new DraftWithOwnerProperty('user-1')));
    }

    public function test_policy_decision_deny_reason_propagates_through_engine(): void
    {
        $registry = new PolicyRegistry(new ServiceLocator([
            'tournaments' => fn() => new PublishedTournamentPolicy(),
        ]));

        $engine = new PolicyEngine(
            $registry,
            new PermissionRegistry(['tournaments.delete.any' => $this->meta('tournaments.delete.any')]),
            new ArrayResolver(new RoleVoter(), ['tournaments.delete.any']),
            new NullEmergencyDenyList(),
            new NullAuthorizationVersionStore(),
            new RoleVoter(),
        );

        $decision = $engine->decide(new UserIdentity('u1', ['ROLE_USER']), 'tournaments.delete.any');

        $this->assertFalse($decision->allowed());
        $this->assertSame('tournament_published', $decision->reason());
    }

    public function test_policy_returning_bool_false_still_yields_resource_denied(): void
    {
        $registry = new PolicyRegistry(new ServiceLocator([
            'widgets' => fn() => new DenyAllPolicy(),
        ]));

        $engine = new PolicyEngine(
            $registry,
            new PermissionRegistry(['widgets.read.any' => $this->meta('widgets.read.any')]),
            new ArrayResolver(new RoleVoter(), ['widgets.read.any']),
            new NullEmergencyDenyList(),
            new NullAuthorizationVersionStore(),
            new RoleVoter(),
        );

        $decision = $engine->decide(new UserIdentity('u1', ['ROLE_USER']), 'widgets.read.any');

        $this->assertFalse($decision->allowed());
        $this->assertSame(AuthorizationDecisionReason::ResourceDenied->value, $decision->reason());
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(string $permission): array
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
            'policyRequired' => false,
            'selfEnforced' => false,
            'group' => $resource,
            'catalogClass' => self::class,
        ];
    }
}

final class DraftWithOwnerProperty
{
    public function __construct(#[Owner] private string $authorId)
    {
    }
}

final class DraftWithOwnerGetter
{
    public function __construct(private string $authorId)
    {
    }

    #[Owner]
    public function ownerId(): string
    {
        return $this->authorId;
    }
}

final class DraftWithIntOwner
{
    public function __construct(#[Owner] private int $authorId)
    {
    }
}

final class StdClassOwnerResolver implements OwnerResolverInterface
{
    public function __construct(private string $ownerId)
    {
    }

    public function resourceType(): string
    {
        return \stdClass::class;
    }

    public function ownerId(object $resource): ?string
    {
        return $this->ownerId;
    }
}

#[\Vortos\Authorization\Attribute\AsPolicy(resource: 'tournaments')]
final class PublishedTournamentPolicy implements PolicyInterface
{
    public function can(AuthorizationContext $auth, string $action, string $scope, mixed $resource = null): bool|PolicyDecision
    {
        if ($action === 'delete') {
            return PolicyDecision::deny('tournament_published');
        }

        return PolicyDecision::allow();
    }
}
