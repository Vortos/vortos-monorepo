<?php

declare(strict_types=1);

namespace Vortos\FeatureFlagsAdmin\Tests\Security;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Identity\AnonymousIdentity;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Cache\Adapter\ArrayAdapter;
use Vortos\FeatureFlagsAdmin\AdminConfig;
use Vortos\FeatureFlagsAdmin\Http\Middleware\AdminAuthMiddleware;
use Vortos\Http\Exception\ForbiddenException;
use Vortos\Http\Request;
use Vortos\Http\Response;

final class AdminAuthMiddlewareTest extends TestCase
{
    public function test_anonymous_user_redirected_to_login(): void
    {
        $middleware = $this->buildMiddleware(null);
        $request = Request::create('/admin/flags');

        $response = $middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/login', $response->headers->get('Location'));
        $this->assertStringContainsString('redirect=', $response->headers->get('Location'));
    }

    public function test_authenticated_admin_passes_through(): void
    {
        $middleware = $this->buildMiddleware(new UserIdentity('admin-1', ['ROLE_ADMIN']));
        $request = Request::create('/admin/flags');

        $response = $middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_authenticated_non_admin_gets_403(): void
    {
        $middleware = $this->buildMiddleware(new UserIdentity('user-1', ['ROLE_USER']));
        $request = Request::create('/admin/flags');

        $this->expectException(ForbiddenException::class);
        $middleware->handle($request, fn() => new Response('ok'));
    }

    public function test_non_admin_routes_pass_through(): void
    {
        $middleware = $this->buildMiddleware(null);
        $request = Request::create('/api/flags');

        $response = $middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_subpath_is_protected(): void
    {
        $middleware = $this->buildMiddleware(null);
        $request = Request::create('/admin/flags/detail/my-flag');

        $response = $middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(302, $response->getStatusCode());
    }

    public function test_redirect_preserves_original_path(): void
    {
        $middleware = $this->buildMiddleware(null);
        $request = Request::create('/admin/flags/detail/my-flag');

        $response = $middleware->handle($request, fn() => new Response('ok'));

        $this->assertStringContainsString(
            urlencode('/admin/flags/detail/my-flag'),
            $response->headers->get('Location'),
        );
    }

    public function test_custom_required_role(): void
    {
        $config = new AdminConfig(true, '/admin/flags', 'ROLE_FLAGS_ADMIN');
        $adapter = new ArrayAdapter();
        $adapter->set('auth:identity', new UserIdentity('user-1', ['ROLE_ADMIN']));
        $middleware = new AdminAuthMiddleware(new CurrentUserProvider($adapter), $config);

        $request = Request::create('/admin/flags');

        $this->expectException(ForbiddenException::class);
        $middleware->handle($request, fn() => new Response('ok'));
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
