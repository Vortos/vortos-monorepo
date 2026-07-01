<?php

declare(strict_types=1);

namespace Vortos\SchedulerAdmin\Tests\Security;

use PHPUnit\Framework\TestCase;
use Vortos\Http\Request;
use Vortos\Http\Response;
use Vortos\SchedulerAdmin\AdminConfig;
use Vortos\SchedulerAdmin\Http\Middleware\AdminCspMiddleware;

final class AdminCspMiddlewareTest extends TestCase
{
    private AdminCspMiddleware $middleware;

    protected function setUp(): void
    {
        $this->middleware = new AdminCspMiddleware(new AdminConfig());
    }

    public function test_csp_header_added_to_admin_responses(): void
    {
        $request  = Request::create('/admin/scheduler');
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertNotNull($response->headers->get('Content-Security-Policy'));
    }

    public function test_csp_frame_ancestors_none(): void
    {
        $request  = Request::create('/admin/scheduler');
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertStringContainsString(
            "frame-ancestors 'none'",
            (string) $response->headers->get('Content-Security-Policy'),
        );
    }

    public function test_csp_form_action_self(): void
    {
        $request  = Request::create('/admin/scheduler');
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertStringContainsString(
            "form-action 'self'",
            (string) $response->headers->get('Content-Security-Policy'),
        );
    }

    public function test_csp_no_unsafe_inline(): void
    {
        $request  = Request::create('/admin/scheduler');
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertStringNotContainsString("'unsafe-inline'", $csp);
        $this->assertStringNotContainsString("'unsafe-eval'", $csp);
    }

    public function test_x_frame_options_deny(): void
    {
        $request  = Request::create('/admin/scheduler');
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
    }

    public function test_x_content_type_options_nosniff(): void
    {
        $request  = Request::create('/admin/scheduler');
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_referrer_policy_same_origin(): void
    {
        $request  = Request::create('/admin/scheduler');
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame('same-origin', $response->headers->get('Referrer-Policy'));
    }

    public function test_nonce_stored_in_request_attributes(): void
    {
        $request  = Request::create('/admin/scheduler');
        $this->middleware->handle($request, function (Request $r) use (&$nonce) {
            $nonce = $r->attributes->get('_csp_nonce');

            return new Response('ok');
        });

        $this->assertNotNull($nonce);
        $this->assertNotEmpty($nonce);
    }

    public function test_nonce_is_base64(): void
    {
        $request = Request::create('/admin/scheduler');
        $this->middleware->handle($request, function (Request $r) use (&$nonce) {
            $nonce = $r->attributes->get('_csp_nonce');

            return new Response('ok');
        });

        $this->assertNotNull($nonce);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9+\/=]+$/', (string) $nonce);
    }

    public function test_nonce_used_in_script_src(): void
    {
        $request = Request::create('/admin/scheduler');
        $this->middleware->handle($request, function (Request $r) use (&$nonce) {
            $nonce = $r->attributes->get('_csp_nonce');

            return new Response('ok');
        });

        $request2  = Request::create('/admin/scheduler');
        $response2 = $this->middleware->handle($request2, fn() => new Response('ok'));
        $csp       = (string) $response2->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("'nonce-", $csp);
    }

    public function test_non_admin_routes_not_touched(): void
    {
        $request  = Request::create('/api/something');
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertNull($response->headers->get('Content-Security-Policy'));
    }

    public function test_each_request_gets_unique_nonce(): void
    {
        $nonce1 = null;
        $nonce2 = null;

        $request1 = Request::create('/admin/scheduler');
        $this->middleware->handle($request1, function (Request $r) use (&$nonce1) {
            $nonce1 = $r->attributes->get('_csp_nonce');

            return new Response('ok');
        });

        $request2 = Request::create('/admin/scheduler');
        $this->middleware->handle($request2, function (Request $r) use (&$nonce2) {
            $nonce2 = $r->attributes->get('_csp_nonce');

            return new Response('ok');
        });

        $this->assertNotSame($nonce1, $nonce2, 'Each request must get a fresh, unique nonce');
    }
}
