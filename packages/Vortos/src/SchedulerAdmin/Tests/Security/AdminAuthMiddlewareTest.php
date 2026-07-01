<?php

declare(strict_types=1);

namespace Vortos\SchedulerAdmin\Tests\Security;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Identity\AnonymousIdentity;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Cache\Adapter\ArrayAdapter;
use Vortos\Http\Exception\ForbiddenException;
use Vortos\Http\Request;
use Vortos\Http\Response;
use Vortos\SchedulerAdmin\AdminConfig;
use Vortos\SchedulerAdmin\Http\Middleware\AdminAuthMiddleware;

final class AdminAuthMiddlewareTest extends TestCase
{
    public function test_anonymous_redirected_to_login(): void
    {
        $middleware = $this->buildMiddleware(null);
        $request    = Request::create('/admin/scheduler');

        $response = $middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/login', (string) $response->headers->get('Location'));
        $this->assertStringContainsString('redirect=', (string) $response->headers->get('Location'));
    }

    public function test_admin_user_passes_through(): void
    {
        $middleware = $this->buildMiddleware(new UserIdentity('admin-1', ['ROLE_SCHEDULER_ADMIN']));
        $request    = Request::create('/admin/scheduler');

        $response = $middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_wrong_role_throws_forbidden(): void
    {
        $middleware = $this->buildMiddleware(new UserIdentity('user-1', ['ROLE_USER']));
        $request    = Request::create('/admin/scheduler');

        $this->expectException(ForbiddenException::class);
        $middleware->handle($request, fn() => new Response('ok'));
    }

    public function test_non_admin_routes_pass_through_unauthenticated(): void
    {
        $middleware = $this->buildMiddleware(null);
        $request    = Request::create('/api/scheduler/status');

        $response = $middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_subpath_is_also_protected(): void
    {
        $middleware = $this->buildMiddleware(null);
        $request    = Request::create('/admin/scheduler/some-uuid/edit');

        $response = $middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(302, $response->getStatusCode());
    }

    public function test_redirect_url_encodes_original_path(): void
    {
        $middleware = $this->buildMiddleware(null);
        $request    = Request::create('/admin/scheduler/some-uuid/edit');

        $response = $middleware->handle($request, fn() => new Response('ok'));

        $this->assertStringContainsString(
            urlencode('/admin/scheduler/some-uuid/edit'),
            (string) $response->headers->get('Location'),
        );
    }

    public function test_custom_required_role(): void
    {
        $config  = new AdminConfig(prefix: '/admin/scheduler', requiredRole: 'ROLE_OPS');
        $adapter = new ArrayAdapter();
        $adapter->set('auth:identity', new UserIdentity('user-1', ['ROLE_SCHEDULER_ADMIN']));

        $middleware = new AdminAuthMiddleware(new CurrentUserProvider($adapter), $config);
        $request    = Request::create('/admin/scheduler');

        $this->expectException(ForbiddenException::class);
        $middleware->handle($request, fn() => new Response('ok'));
    }

    public function test_approvals_subpath_is_protected(): void
    {
        $middleware = $this->buildMiddleware(null);
        $request    = Request::create('/admin/scheduler/approvals');

        $response = $middleware->handle($request, fn() => new Response('ok'));
        $this->assertSame(302, $response->getStatusCode());
    }

    private function buildMiddleware(?UserIdentity $user): AdminAuthMiddleware
    {
        $adapter = new ArrayAdapter();
        if ($user !== null) {
            $adapter->set('auth:identity', $user);
        }

        return new AdminAuthMiddleware(
            new CurrentUserProvider($adapter),
            new AdminConfig(),
        );
    }
}
