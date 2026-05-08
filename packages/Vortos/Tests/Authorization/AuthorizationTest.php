<?php

declare(strict_types=1);

namespace Tests\Authorization;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Auth\Identity\AnonymousIdentity;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Authorization\Attribute\AsPolicy;
use Vortos\Authorization\Attribute\RequiresPermission;
use Vortos\Authorization\Context\AuthorizationContext;
use Vortos\Authorization\Contract\AuthorizationVersionStoreInterface;
use Vortos\Authorization\Contract\EmergencyDenyListInterface;
use Vortos\Authorization\Contract\PermissionResolverInterface;
use Vortos\Authorization\Contract\PolicyInterface;
use Vortos\Authorization\Decision\AuthorizationDecisionReason;
use Vortos\Authorization\Engine\PolicyEngine;
use Vortos\Authorization\Engine\PolicyRegistry;
use Vortos\Authorization\Exception\AccessDeniedException;
use Vortos\Authorization\Exception\PolicyNotFoundException;
use Vortos\Authorization\Middleware\AuthorizationMiddleware;
use Vortos\Authorization\Middleware\ControllerPermissionMap;
use Vortos\Authorization\Permission\PermissionRegistry;
use Vortos\Authorization\Permission\ResolvedPermissions;
use Vortos\Authorization\Scope\Contract\ScopedPermissionStoreInterface;
use Vortos\Authorization\Scope\Contract\ScopeMode;
use Vortos\Authorization\Storage\NullAuthorizationVersionStore;
use Vortos\Authorization\Storage\NullEmergencyDenyList;
use Vortos\Authorization\Voter\RoleVoter;
use Vortos\Cache\Adapter\ArrayAdapter;

#[AsPolicy(resource: 'articles')]
final class ArticlePolicy implements PolicyInterface
{
    public function can(AuthorizationContext $auth, string $action, string $scope, mixed $resource = null): bool
    {
        return match ($action) {
            'list', 'read' => true,
            'create' => $auth->atLeast('ROLE_EDITOR'),
            'update' => $scope === 'own'
                ? ($resource !== null && $resource === $auth->user()->id())
                : $auth->hasRole('ROLE_ADMIN'),
            'delete' => $auth->hasRole('ROLE_ADMIN'),
            default => false,
        };
    }
}

#[AsPolicy(resource: 'users')]
final class UserPolicy implements PolicyInterface
{
    public function can(AuthorizationContext $auth, string $action, string $scope, mixed $resource = null): bool
    {
        return match ($action) {
            'list' => $auth->hasRole('ROLE_ADMIN'),
            'delete' => $auth->hasRole('ROLE_SUPER_ADMIN'),
            default => false,
        };
    }
}

#[RequiresPermission('articles.create.any')]
final class CreateArticleController
{
    public function __invoke(): Response
    {
        return new Response('created');
    }
}

#[RequiresPermission('articles.update.own', resourceParam: 'articleId')]
final class UpdateArticleController
{
    public function __invoke(): Response
    {
        return new Response('updated');
    }
}

#[RequiresPermission('articles.list.any')]
#[RequiresPermission('users.list.any')]
final class AdminDashboardController
{
    public function __invoke(): Response
    {
        return new Response('dashboard');
    }
}

#[RequiresPermission('articles.list.any', scope: 'org')]
final class OrgArticlesController
{
    public function __invoke(): Response
    {
        return new Response('org articles');
    }
}

final class PublicController
{
    public function __invoke(): Response
    {
        return new Response('public');
    }
}

final class TestPermissionResolver implements PermissionResolverInterface
{
    /**
     * @param array<string, string[]> $rolePermissions
     */
    public function __construct(
        private readonly RoleVoter $roleVoter,
        private readonly array $rolePermissions,
    ) {
    }

    public function resolve(UserIdentityInterface $identity): ResolvedPermissions
    {
        if (!$identity->isAuthenticated()) {
            return ResolvedPermissions::empty();
        }

        $expandedRoles = $this->roleVoter->expandRoleNames($identity->roles());
        $permissions = [];

        foreach ($expandedRoles as $role) {
            array_push($permissions, ...($this->rolePermissions[$role] ?? []));
        }

        return new ResolvedPermissions($identity->id(), $identity->roles(), $expandedRoles, $permissions);
    }

    public function has(UserIdentityInterface $identity, string $permission): bool
    {
        return $this->resolve($identity)->has($permission);
    }
}

final class TestScopedPermissionStore implements ScopedPermissionStoreInterface
{
    /** @var array<string, true> */
    private array $grants = [];

    public function grant(
        string $userId,
        string $scopeName,
        string $scopeId,
        string $permission,
        ?\DateTimeImmutable $expiresAt = null,
    ): void {
        $this->grants[$this->key($userId, $scopeName, $scopeId, $permission)] = true;
    }

    public function revoke(
        string $userId,
        string $scopeName,
        string $scopeId,
        string $permission,
    ): void {
        unset($this->grants[$this->key($userId, $scopeName, $scopeId, $permission)]);
    }

    public function has(
        string $userId,
        string $scopeName,
        string $scopeId,
        string $permission,
    ): bool {
        return isset($this->grants[$this->key($userId, $scopeName, $scopeId, $permission)]);
    }

    public function revokeAll(string $userId, string $scopeName, string $scopeId): void
    {
        foreach (array_keys($this->grants) as $key) {
            if (str_starts_with($key, $userId . '|' . $scopeName . '|' . $scopeId . '|')) {
                unset($this->grants[$key]);
            }
        }
    }

    private function key(string $userId, string $scopeName, string $scopeId, string $permission): string
    {
        return $userId . '|' . $scopeName . '|' . $scopeId . '|' . $permission;
    }
}

final class AuthorizationTest extends TestCase
{
    private PolicyEngine $engine;
    private PolicyRegistry $registry;
    private RoleVoter $roleVoter;
    private ArrayAdapter $arrayAdapter;
    private HttpKernelInterface $stubKernel;

    protected function setUp(): void
    {
        $this->roleVoter = new RoleVoter([
            'ROLE_SUPER_ADMIN' => ['ROLE_ADMIN'],
            'ROLE_ADMIN' => ['ROLE_EDITOR'],
            'ROLE_EDITOR' => ['ROLE_USER'],
        ]);

        $this->registry = new PolicyRegistry(new ServiceLocator([
            'articles' => fn() => new ArticlePolicy(),
            'users' => fn() => new UserPolicy(),
        ]));

        $permissionRegistry = new PermissionRegistry([
            'articles.create.any' => $this->permission('articles.create.any'),
            'articles.delete.any' => $this->permission('articles.delete.any'),
            'articles.list.any' => $this->permission('articles.list.any'),
            'articles.update.own' => $this->permission('articles.update.own'),
            'users.list.any' => $this->permission('users.list.any'),
            'users.delete.any' => $this->permission('users.delete.any', dangerous: true),
        ]);

        $resolver = new TestPermissionResolver($this->roleVoter, [
            'ROLE_USER' => ['articles.list.any', 'articles.update.own'],
            'ROLE_EDITOR' => ['articles.create.any'],
            'ROLE_ADMIN' => ['articles.delete.any', 'users.list.any'],
        ]);

        $this->engine = new PolicyEngine(
            $this->registry,
            $permissionRegistry,
            $resolver,
            new NullEmergencyDenyList(),
            new NullAuthorizationVersionStore(),
            $this->roleVoter,
        );

        $this->arrayAdapter = new ArrayAdapter();
        $this->stubKernel = new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response('ok', 200);
            }
        };
    }

    protected function tearDown(): void
    {
        $this->arrayAdapter->clear();
    }

    public function test_can_returns_false_for_anonymous_user(): void
    {
        $this->assertFalse($this->engine->can(new AnonymousIdentity(), 'articles.create.any'));
    }

    public function test_can_returns_true_when_resolver_and_policy_allow(): void
    {
        $this->assertTrue($this->engine->can(new UserIdentity('user-1', ['ROLE_EDITOR']), 'articles.create.any'));
    }

    public function test_can_returns_false_when_resolved_permission_is_missing(): void
    {
        $decision = $this->engine->decide(new UserIdentity('user-1', ['ROLE_USER']), 'articles.create.any');

        $this->assertFalse($decision->allowed());
        $this->assertSame(AuthorizationDecisionReason::MissingPermission->value, $decision->reason());
    }

    public function test_can_returns_false_when_policy_denies_resource(): void
    {
        $decision = $this->engine->decide(
            new UserIdentity('owner', ['ROLE_USER']),
            'articles.update.own',
            'other-user',
        );

        $this->assertFalse($decision->allowed());
        $this->assertSame(AuthorizationDecisionReason::ResourceDenied->value, $decision->reason());
    }

    public function test_can_returns_false_for_unknown_permission(): void
    {
        $decision = $this->engine->decide(new UserIdentity('user-1', ['ROLE_ADMIN']), 'competitions.create.any');

        $this->assertFalse($decision->allowed());
        $this->assertSame(AuthorizationDecisionReason::UnknownPermission->value, $decision->reason());
    }

    public function test_can_returns_false_for_invalid_permission_format(): void
    {
        $decision = $this->engine->decide(new UserIdentity('user-1', ['ROLE_ADMIN']), 'invalid-format');

        $this->assertFalse($decision->allowed());
        $this->assertSame(AuthorizationDecisionReason::InvalidPermissionFormat->value, $decision->reason());
    }

    public function test_stale_authz_version_claim_is_rejected_before_permission_resolution(): void
    {
        $versionStore = new class implements AuthorizationVersionStoreInterface {
            public function versionForUser(string $userId): int { return 3; }
            public function increment(string $userId): int { return 4; }
        };

        $engine = new PolicyEngine(
            $this->registry,
            new PermissionRegistry(['articles.list.any' => $this->permission('articles.list.any')]),
            new TestPermissionResolver($this->roleVoter, ['ROLE_USER' => ['articles.list.any']]),
            new NullEmergencyDenyList(),
            $versionStore,
            $this->roleVoter,
        );

        $decision = $engine->decide(
            new UserIdentity('regular-user', ['ROLE_USER'], ['authz_version' => 2]),
            'articles.list.any',
        );

        $this->assertFalse($decision->allowed());
        $this->assertSame(AuthorizationDecisionReason::StaleToken->value, $decision->reason());
    }

    public function test_scoped_decision_requires_normal_permission_and_scoped_grant(): void
    {
        $store = new TestScopedPermissionStore();
        $store->grant('regular-user', 'org', 'org-1', 'articles.list.any');

        $decision = $this->scopedEngine($store)->decideScoped(
            new UserIdentity('regular-user', ['ROLE_USER']),
            'articles.list.any',
            ['org' => 'org-1'],
        );

        $this->assertTrue($decision->allowed());
    }

    public function test_scoped_decision_denies_when_scoped_grant_missing(): void
    {
        $decision = $this->scopedEngine(new TestScopedPermissionStore())->decideScoped(
            new UserIdentity('regular-user', ['ROLE_USER']),
            'articles.list.any',
            ['org' => 'org-1'],
        );

        $this->assertFalse($decision->allowed());
        $this->assertSame(AuthorizationDecisionReason::ScopedPermissionDenied->value, $decision->reason());
    }

    public function test_scoped_decision_any_mode_allows_one_matching_scope(): void
    {
        $store = new TestScopedPermissionStore();
        $store->grant('regular-user', 'team', 'team-1', 'articles.list.any');

        $decision = $this->scopedEngine($store)->decideScoped(
            new UserIdentity('regular-user', ['ROLE_USER']),
            'articles.list.any',
            ['org' => 'org-1', 'team' => 'team-1'],
            ScopeMode::Any,
        );

        $this->assertTrue($decision->allowed());
    }

    public function test_break_glass_bypass_is_disabled_by_default(): void
    {
        $decision = $this->breakGlassBypassEngine(enabled: false)->decide(
            new UserIdentity('super-user', ['ROLE_SUPER_ADMIN']),
            'users.list.any',
        );

        $this->assertFalse($decision->allowed());
        $this->assertSame(AuthorizationDecisionReason::MissingPermission->value, $decision->reason());
    }

    public function test_break_glass_bypass_allows_explicitly_bypassable_permission_without_runtime_grant(): void
    {
        $decision = $this->breakGlassBypassEngine(enabled: true)->decide(
            new UserIdentity('super-user', ['ROLE_SUPER_ADMIN']),
            'users.list.any',
        );

        $this->assertTrue($decision->allowed());
    }

    public function test_break_glass_bypass_does_not_bypass_permission_unless_catalog_marks_it_bypassable(): void
    {
        $decision = $this->breakGlassBypassEngine(enabled: true)->decide(
            new UserIdentity('super-user', ['ROLE_SUPER_ADMIN']),
            'users.read.any',
        );

        $this->assertFalse($decision->allowed());
        $this->assertSame(AuthorizationDecisionReason::MissingPermission->value, $decision->reason());
    }

    public function test_break_glass_bypass_does_not_bypass_dangerous_permission_without_explicit_bypassable_metadata(): void
    {
        $decision = $this->breakGlassBypassEngine(enabled: true)->decide(
            new UserIdentity('super-user', ['ROLE_SUPER_ADMIN']),
            'users.delete.any',
        );

        $this->assertFalse($decision->allowed());
        $this->assertSame(AuthorizationDecisionReason::MissingPermission->value, $decision->reason());
    }

    public function test_critical_decision_disables_break_glass_bypass(): void
    {
        $decision = $this->breakGlassBypassEngine(enabled: true)->decideCritical(
            new UserIdentity('super-user', ['ROLE_SUPER_ADMIN']),
            'users.list.any',
        );

        $this->assertFalse($decision->allowed());
        $this->assertSame(AuthorizationDecisionReason::MissingPermission->value, $decision->reason());
    }

    public function test_emergency_deny_list_takes_precedence_over_break_glass_bypass(): void
    {
        $denyList = new class implements EmergencyDenyListInterface {
            public function isDenied(string $userId): bool { return true; }
            public function deny(string $userId): void {}
            public function allow(string $userId): void {}
        };

        $decision = $this->breakGlassBypassEngine(enabled: true, denyList: $denyList)->decide(
            new UserIdentity('super-user', ['ROLE_SUPER_ADMIN']),
            'users.list.any',
        );

        $this->assertFalse($decision->allowed());
        $this->assertSame(AuthorizationDecisionReason::EmergencyDenied->value, $decision->reason());
    }

    public function test_authorize_throws_for_anonymous_user(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->engine->authorize(new AnonymousIdentity(), 'articles.create.any');
    }

    public function test_authorize_throws_for_authenticated_user_without_permission(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->engine->authorize(new UserIdentity('user-1', ['ROLE_USER']), 'articles.create.any');
    }

    public function test_authorize_does_not_throw_when_allowed(): void
    {
        $this->expectNotToPerformAssertions();

        $this->engine->authorize(new UserIdentity('user-1', ['ROLE_ADMIN']), 'articles.delete.any');
    }

    public function test_registry_finds_policy_for_known_resource(): void
    {
        $this->assertInstanceOf(ArticlePolicy::class, $this->registry->findForResource('articles'));
    }

    public function test_registry_throws_for_unknown_resource(): void
    {
        $this->expectException(PolicyNotFoundException::class);

        $this->registry->findForResource('nonexistent');
    }

    public function test_role_voter_hierarchy_expansion(): void
    {
        $identity = new UserIdentity('super', ['ROLE_SUPER_ADMIN']);

        $this->assertTrue($this->roleVoter->hasRole($identity, 'ROLE_SUPER_ADMIN'));
        $this->assertTrue($this->roleVoter->hasRole($identity, 'ROLE_ADMIN'));
        $this->assertTrue($this->roleVoter->hasRole($identity, 'ROLE_EDITOR'));
        $this->assertTrue($this->roleVoter->hasRole($identity, 'ROLE_USER'));
    }

    public function test_public_route_passes_through(): void
    {
        $event = $this->makeEvent(PublicController::class, 'admin');

        $this->makeMiddleware()->onKernelRequest($event);

        $this->assertNull($event->getResponse());
    }

    public function test_protected_route_anonymous_user_returns_401(): void
    {
        $event = $this->makeEvent(CreateArticleController::class, 'anonymous');

        $this->makeMiddleware()->onKernelRequest($event);

        $this->assertSame(401, $event->getResponse()?->getStatusCode());
    }

    public function test_protected_route_unauthorized_user_returns_403(): void
    {
        $event = $this->makeEvent(CreateArticleController::class, 'user');

        $this->makeMiddleware()->onKernelRequest($event);

        $this->assertSame(403, $event->getResponse()?->getStatusCode());
    }

    public function test_protected_route_authorized_user_passes(): void
    {
        $event = $this->makeEvent(CreateArticleController::class, 'editor');

        $this->makeMiddleware()->onKernelRequest($event);

        $this->assertNull($event->getResponse());
    }

    public function test_ownership_scope_check_with_matching_resource(): void
    {
        $event = $this->makeEvent(UpdateArticleController::class, 'regular-user', ['articleId' => 'regular-user']);

        $this->makeMiddleware()->onKernelRequest($event);

        $this->assertNull($event->getResponse());
    }

    public function test_ownership_scope_check_with_non_matching_resource(): void
    {
        $event = $this->makeEvent(UpdateArticleController::class, 'regular-user', ['articleId' => 'other-user']);

        $this->makeMiddleware()->onKernelRequest($event);

        $this->assertSame(403, $event->getResponse()?->getStatusCode());
    }

    public function test_multiple_permissions_all_must_pass(): void
    {
        $event = $this->makeEvent(AdminDashboardController::class, 'admin');

        $this->makeMiddleware()->onKernelRequest($event);

        $this->assertNull($event->getResponse());
    }

    public function test_multiple_permissions_fails_if_one_denied(): void
    {
        $event = $this->makeEvent(AdminDashboardController::class, 'editor');

        $this->makeMiddleware()->onKernelRequest($event);

        $this->assertSame(403, $event->getResponse()?->getStatusCode());
    }

    public function test_scoped_route_passes_when_request_scope_has_grant(): void
    {
        $store = new TestScopedPermissionStore();
        $store->grant('regular-user', 'org', 'org-1', 'articles.list.any');
        $event = $this->makeEvent(OrgArticlesController::class, 'user', ['orgId' => 'org-1']);

        $this->makeMiddleware($store)->onKernelRequest($event);

        $this->assertNull($event->getResponse());
    }

    public function test_scoped_route_denies_when_request_scope_grant_is_missing(): void
    {
        $event = $this->makeEvent(OrgArticlesController::class, 'user', ['orgId' => 'org-1']);

        $this->makeMiddleware(new TestScopedPermissionStore())->onKernelRequest($event);

        $this->assertSame(403, $event->getResponse()?->getStatusCode());
    }

    public function test_permission_format_parsing(): void
    {
        // Valid format: engine accepts and evaluates it (denied only because user lacks the permission,
        // not because of a format error)
        $identity = new UserIdentity('user-1', ['ROLE_USER']);
        $decision = $this->engine->decide($identity, 'articles.update.own');
        $this->assertNotSame('invalid_permission_format', $decision->reason());
    }

    public function test_invalid_permission_format_is_denied(): void
    {
        $identity = new UserIdentity('user-1', ['ROLE_USER']);
        $decision = $this->engine->decide($identity, 'invalid-format');
        $this->assertFalse($decision->allowed());
        $this->assertSame('invalid_permission_format', $decision->reason());
    }

    private function makeMiddleware(?ScopedPermissionStoreInterface $scopedPermissions = null): AuthorizationMiddleware
    {
        $engine = $scopedPermissions === null ? $this->engine : $this->scopedEngine($scopedPermissions);

        return new AuthorizationMiddleware(
            $engine,
            new CurrentUserProvider($this->arrayAdapter),
            new ControllerPermissionMap([
                CreateArticleController::class => [[
                    'permission' => 'articles.create.any',
                    'resourceParam' => null,
                    'scope' => null,
                    'scopeMode' => 'All',
                ]],
                UpdateArticleController::class => [[
                    'permission' => 'articles.update.own',
                    'resourceParam' => 'articleId',
                    'scope' => null,
                    'scopeMode' => 'All',
                ]],
                AdminDashboardController::class => [
                    [
                        'permission' => 'articles.list.any',
                        'resourceParam' => null,
                        'scope' => null,
                        'scopeMode' => 'All',
                    ],
                    [
                        'permission' => 'users.list.any',
                        'resourceParam' => null,
                        'scope' => null,
                        'scopeMode' => 'All',
                    ],
                ],
                OrgArticlesController::class => [[
                    'permission' => 'articles.list.any',
                    'resourceParam' => null,
                    'scope' => 'org',
                    'scopeMode' => 'All',
                ]],
            ]),
        );
    }

    /**
     * @param array<string, string> $routeParams
     */
    private function makeEvent(string $controllerClass, string $identity = 'authenticated', array $routeParams = []): RequestEvent
    {
        $request = Request::create('/test');
        $request->attributes->set('_controller', $controllerClass);

        foreach ($routeParams as $key => $value) {
            $request->attributes->set($key, $value);
        }

        $this->arrayAdapter->set('auth:identity', match ($identity) {
            'anonymous' => new AnonymousIdentity(),
            'admin' => new UserIdentity('admin-user', ['ROLE_ADMIN']),
            'editor' => new UserIdentity('editor-user', ['ROLE_EDITOR']),
            'user' => new UserIdentity('regular-user', ['ROLE_USER']),
            'super_admin' => new UserIdentity('super-user', ['ROLE_SUPER_ADMIN']),
            default => new UserIdentity($identity, ['ROLE_USER']),
        });

        return new RequestEvent($this->stubKernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    /**
     * @return array<string, string|bool|null>
     */
    private function breakGlassBypassEngine(
        bool $enabled,
        ?EmergencyDenyListInterface $denyList = null,
    ): PolicyEngine {
        $permissionRegistry = new PermissionRegistry([
            'users.list.any' => $this->permission('users.list.any', bypassable: true),
            'users.read.any' => $this->permission('users.read.any'),
            'users.delete.any' => $this->permission('users.delete.any', dangerous: true),
        ]);

        return new PolicyEngine(
            $this->registry,
            $permissionRegistry,
            new TestPermissionResolver($this->roleVoter, []),
            $denyList ?? new NullEmergencyDenyList(),
            new NullAuthorizationVersionStore(),
            $this->roleVoter,
            authzVersionCheck: true,
            breakGlassBypass: $enabled,
            breakGlassRole: 'ROLE_SUPER_ADMIN',
        );
    }

    private function scopedEngine(ScopedPermissionStoreInterface $scopedPermissions): PolicyEngine
    {
        $permissionRegistry = new PermissionRegistry([
            'articles.list.any' => $this->permission('articles.list.any'),
            'articles.create.any' => $this->permission('articles.create.any'),
            'articles.update.own' => $this->permission('articles.update.own'),
            'users.list.any' => $this->permission('users.list.any'),
        ]);

        $resolver = new TestPermissionResolver($this->roleVoter, [
            'ROLE_USER' => ['articles.list.any', 'articles.update.own'],
            'ROLE_EDITOR' => ['articles.create.any'],
            'ROLE_ADMIN' => ['users.list.any'],
        ]);

        return new PolicyEngine(
            $this->registry,
            $permissionRegistry,
            $resolver,
            new NullEmergencyDenyList(),
            new NullAuthorizationVersionStore(),
            $this->roleVoter,
            scopedPermissions: $scopedPermissions,
        );
    }

    private function permission(string $permission, bool $dangerous = false, bool $bypassable = false): array
    {
        [$resource, $action, $scope] = explode('.', $permission);

        return [
            'permission' => $permission,
            'resource' => $resource,
            'action' => $action,
            'scope' => $scope,
            'label' => ucfirst($action) . ' ' . $scope,
            'description' => null,
            'dangerous' => $dangerous,
            'bypassable' => $bypassable,
            'group' => ucfirst($resource),
            'catalogClass' => self::class,
        ];
    }
}
