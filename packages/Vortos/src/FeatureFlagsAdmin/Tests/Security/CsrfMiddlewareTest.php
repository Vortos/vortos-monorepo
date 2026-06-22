<?php

declare(strict_types=1);

namespace Vortos\FeatureFlagsAdmin\Tests\Security;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Vortos\FeatureFlagsAdmin\AdminConfig;
use Vortos\FeatureFlagsAdmin\Http\Middleware\CsrfMiddleware;
use Vortos\FeatureFlagsAdmin\Security\CsrfTokenManager;
use Vortos\Http\Exception\ForbiddenException;
use Vortos\Http\Request;
use Vortos\Http\Response;

final class CsrfMiddlewareTest extends TestCase
{
    private CsrfMiddleware $middleware;
    private CsrfTokenManager $csrf;

    protected function setUp(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $requestStack = new RequestStack();
        $request = new Request();
        $request->setSession($session);
        $requestStack->push($request);

        $this->csrf = new CsrfTokenManager($requestStack);
        $this->middleware = new CsrfMiddleware($this->csrf, new AdminConfig());
    }

    public function test_get_requests_pass_without_token(): void
    {
        $request = Request::create('/admin/flags', 'GET');
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_head_requests_pass_without_token(): void
    {
        $request = Request::create('/admin/flags', 'HEAD');
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_post_without_token_is_rejected(): void
    {
        $request = Request::create('/admin/flags/fragment/test/toggle', 'POST');

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('CSRF');
        $this->middleware->handle($request, fn() => new Response('ok'));
    }

    public function test_post_with_invalid_token_is_rejected(): void
    {
        $request = Request::create('/admin/flags/fragment/test/toggle', 'POST');
        $request->headers->set('X-CSRF-Token', 'invalid-token');

        $this->expectException(ForbiddenException::class);
        $this->middleware->handle($request, fn() => new Response('ok'));
    }

    public function test_post_with_valid_header_token_passes(): void
    {
        $token = $this->csrf->getToken();
        $request = Request::create('/admin/flags/fragment/test/toggle', 'POST');
        $request->headers->set('X-CSRF-Token', $token);

        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_post_with_valid_body_token_passes(): void
    {
        $token = $this->csrf->getToken();
        $request = Request::create('/admin/flags/fragment/test/toggle', 'POST', ['_csrf_token' => $token]);

        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_put_without_token_is_rejected(): void
    {
        $request = Request::create('/admin/flags/fragment/test/rules', 'PUT');

        $this->expectException(ForbiddenException::class);
        $this->middleware->handle($request, fn() => new Response('ok'));
    }

    public function test_delete_without_token_is_rejected(): void
    {
        $request = Request::create('/admin/flags/something', 'DELETE');

        $this->expectException(ForbiddenException::class);
        $this->middleware->handle($request, fn() => new Response('ok'));
    }

    public function test_non_admin_routes_not_csrf_checked(): void
    {
        $request = Request::create('/api/flags', 'POST');
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(200, $response->getStatusCode());
    }
}
