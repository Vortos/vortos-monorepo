<?php

declare(strict_types=1);

namespace Vortos\FeatureFlagsAdmin\Tests\Security;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlagsAdmin\AdminConfig;
use Vortos\FeatureFlagsAdmin\Http\Middleware\AdminCspMiddleware;
use Vortos\Http\Request;
use Vortos\Http\Response;

final class AdminCspMiddlewareTest extends TestCase
{
    private AdminCspMiddleware $middleware;

    protected function setUp(): void
    {
        $this->middleware = new AdminCspMiddleware(new AdminConfig());
    }

    public function test_sets_csp_header_on_admin_routes(): void
    {
        $request = Request::create('/admin/flags');
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($csp);
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("script-src 'self' 'nonce-", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("base-uri 'none'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
    }

    public function test_no_unsafe_inline_in_csp(): void
    {
        $request = Request::create('/admin/flags');
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringNotContainsString('unsafe-inline', $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp);
    }

    public function test_sets_x_frame_options_deny(): void
    {
        $request = Request::create('/admin/flags');
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
    }

    public function test_sets_nosniff_header(): void
    {
        $request = Request::create('/admin/flags');
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_sets_referrer_policy(): void
    {
        $request = Request::create('/admin/flags');
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame('same-origin', $response->headers->get('Referrer-Policy'));
    }

    public function test_sets_permissions_policy(): void
    {
        $request = Request::create('/admin/flags');
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertNotNull($response->headers->get('Permissions-Policy'));
    }

    public function test_generates_nonce_on_request(): void
    {
        $request = Request::create('/admin/flags');
        $this->middleware->handle($request, fn() => new Response('ok'));

        $nonce = $request->attributes->get('_csp_nonce');
        $this->assertNotNull($nonce);
        $this->assertNotEmpty($nonce);
    }

    public function test_does_not_set_csp_on_non_admin_routes(): void
    {
        $request = Request::create('/api/flags');
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertNull($response->headers->get('Content-Security-Policy'));
    }

    public function test_nonce_varies_per_request(): void
    {
        $request1 = Request::create('/admin/flags');
        $this->middleware->handle($request1, fn() => new Response('ok'));

        $request2 = Request::create('/admin/flags');
        $this->middleware->handle($request2, fn() => new Response('ok'));

        $this->assertNotSame(
            $request1->attributes->get('_csp_nonce'),
            $request2->attributes->get('_csp_nonce'),
        );
    }
}
