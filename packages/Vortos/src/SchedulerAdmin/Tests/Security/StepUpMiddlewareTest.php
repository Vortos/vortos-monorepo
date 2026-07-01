<?php

declare(strict_types=1);

namespace Vortos\SchedulerAdmin\Tests\Security;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Cache\Adapter\ArrayAdapter;
use Vortos\Http\Request;
use Vortos\Http\Response;
use Vortos\SchedulerAdmin\AdminConfig;
use Vortos\SchedulerAdmin\Http\Middleware\StepUpMiddleware;
use Vortos\SchedulerAdmin\Security\StepUpGuard;

final class StepUpMiddlewareTest extends TestCase
{
    public function test_get_requests_never_require_step_up(): void
    {
        $middleware = $this->buildMiddleware(twoFaVerified: false);
        $request    = Request::create('/admin/scheduler/some-id/edit', 'GET');

        $response = $middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_non_sensitive_post_passes_without_2fa(): void
    {
        $middleware = $this->buildMiddleware(twoFaVerified: false);
        $request    = Request::create('/admin/scheduler', 'POST');

        $response = $middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_create_post_requires_2fa_when_not_verified(): void
    {
        $middleware = $this->buildMiddleware(twoFaVerified: false);
        $request    = Request::create('/admin/scheduler/create', 'POST');

        $response = $middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/auth/2fa/challenge', (string) $response->headers->get('Location'));
    }

    public function test_delete_post_requires_2fa(): void
    {
        $middleware = $this->buildMiddleware(twoFaVerified: false);
        $request    = Request::create('/admin/scheduler/some-id/delete', 'POST');

        $response = $middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('2fa', (string) $response->headers->get('Location'));
    }

    public function test_approve_post_requires_2fa(): void
    {
        $middleware = $this->buildMiddleware(twoFaVerified: false);
        $request    = Request::create('/admin/scheduler/approvals/some-id/approve', 'POST');

        $response = $middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(302, $response->getStatusCode());
    }

    public function test_reject_post_requires_2fa(): void
    {
        $middleware = $this->buildMiddleware(twoFaVerified: false);
        $request    = Request::create('/admin/scheduler/approvals/some-id/reject', 'POST');

        $response = $middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(302, $response->getStatusCode());
    }

    public function test_run_now_post_requires_2fa(): void
    {
        $middleware = $this->buildMiddleware(twoFaVerified: false);
        $request    = Request::create('/admin/scheduler/some-id/run-now', 'POST');

        $response = $middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('2fa', (string) $response->headers->get('Location'));
    }

    public function test_2fa_verified_fresh_token_passes(): void
    {
        $middleware = $this->buildMiddleware(twoFaVerified: true, freshToken: true);
        $request    = Request::create('/admin/scheduler/create', 'POST');

        $response = $middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_2fa_verified_stale_token_requires_relogin(): void
    {
        $middleware = $this->buildMiddleware(twoFaVerified: true, freshToken: false);
        $request    = Request::create('/admin/scheduler/create', 'POST');

        $response = $middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/login', (string) $response->headers->get('Location'));
        $this->assertStringContainsString('require_fresh=1', (string) $response->headers->get('Location'));
    }

    public function test_redirect_preserves_original_path(): void
    {
        $middleware = $this->buildMiddleware(twoFaVerified: false);
        $request    = Request::create('/admin/scheduler/some-uuid/delete', 'POST');

        $response = $middleware->handle($request, fn() => new Response('ok'));

        $this->assertStringContainsString(
            urlencode('/admin/scheduler/some-uuid/delete'),
            (string) $response->headers->get('Location'),
        );
    }

    public function test_non_admin_routes_not_checked(): void
    {
        $middleware = $this->buildMiddleware(twoFaVerified: false);
        $request    = Request::create('/api/scheduler/run', 'POST');

        $response = $middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(200, $response->getStatusCode());
    }

    private function buildMiddleware(bool $twoFaVerified, bool $freshToken = true): StepUpMiddleware
    {
        $identity = new UserIdentity(
            id:         'user-1',
            roles:      ['ROLE_SCHEDULER_ADMIN'],
            attributes: [
                'twofa_verified_at' => $twoFaVerified ? time() : null,
                'iat'               => $freshToken ? time() : (time() - 10000),
            ],
        );

        $adapter = new ArrayAdapter();
        $adapter->set('auth:identity', $identity);

        $guard     = new StepUpGuard(null, 900);
        $config    = new AdminConfig();

        return new StepUpMiddleware(new CurrentUserProvider($adapter), $guard, $config);
    }
}
