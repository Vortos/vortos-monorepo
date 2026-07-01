<?php

declare(strict_types=1);

namespace Vortos\SchedulerAdmin\Tests\Security;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Vortos\Http\Exception\ForbiddenException;
use Vortos\Http\Request;
use Vortos\Http\Response;
use Vortos\SchedulerAdmin\AdminConfig;
use Vortos\SchedulerAdmin\Http\Middleware\CsrfMiddleware;
use Vortos\SchedulerAdmin\Security\CsrfTokenManager;

final class CsrfMiddlewareTest extends TestCase
{
    private CsrfMiddleware  $middleware;
    private CsrfTokenManager $csrf;

    protected function setUp(): void
    {
        $session      = new Session(new MockArraySessionStorage());
        $requestStack = new RequestStack();
        $request      = new Request();
        $request->setSession($session);
        $requestStack->push($request);

        $this->csrf       = new CsrfTokenManager($requestStack);
        $this->middleware = new CsrfMiddleware($this->csrf, new AdminConfig());
    }

    public function test_get_request_passes_without_token(): void
    {
        $request  = Request::create('/admin/scheduler', 'GET');
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_head_request_passes_without_token(): void
    {
        $request  = Request::create('/admin/scheduler', 'HEAD');
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_options_request_passes_without_token(): void
    {
        $request  = Request::create('/admin/scheduler', 'OPTIONS');
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_post_without_token_is_rejected(): void
    {
        $request = Request::create('/admin/scheduler/create', 'POST');

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessageMatches('/CSRF/i');
        $this->middleware->handle($request, fn() => new Response('ok'));
    }

    public function test_post_with_invalid_header_token_is_rejected(): void
    {
        $request = Request::create('/admin/scheduler/create', 'POST');
        $request->headers->set('X-CSRF-Token', 'totally-invalid');

        $this->expectException(ForbiddenException::class);
        $this->middleware->handle($request, fn() => new Response('ok'));
    }

    public function test_post_with_valid_header_token_passes(): void
    {
        $token   = $this->csrf->getToken();
        $request = Request::create('/admin/scheduler/create', 'POST');
        $request->headers->set('X-CSRF-Token', $token);

        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_post_with_valid_body_token_passes(): void
    {
        $token    = $this->csrf->getToken();
        $request  = Request::create('/admin/scheduler/create', 'POST', ['_csrf_token' => $token]);
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_delete_without_token_is_rejected(): void
    {
        $request = Request::create('/admin/scheduler/some-id/delete', 'DELETE');

        $this->expectException(ForbiddenException::class);
        $this->middleware->handle($request, fn() => new Response('ok'));
    }

    public function test_non_admin_post_is_not_csrf_checked(): void
    {
        $request  = Request::create('/api/external', 'POST');
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_header_token_takes_priority_over_body_token(): void
    {
        $validToken   = $this->csrf->getToken();
        $request      = Request::create('/admin/scheduler/create', 'POST', ['_csrf_token' => 'bad-body-token']);
        $request->headers->set('X-CSRF-Token', $validToken);

        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(200, $response->getStatusCode());
    }
}
