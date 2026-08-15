<?php

declare(strict_types=1);

namespace Vortos\Security\Tests\Headers;

use PHPUnit\Framework\TestCase;
use Vortos\Http\Request;
use Vortos\Http\Response;
use Vortos\Security\Headers\ContentSecurityPolicyBuilder;
use Vortos\Security\Headers\Middleware\SecurityHeadersMiddleware;

/**
 * The global CSP is a default, not an override.
 *
 * This middleware is a response subscriber, so it is the last thing to touch the
 * response. A blanket set() therefore replaced any policy a route had built for
 * itself — including a server-rendered console's per-request nonce, which a
 * static application-wide policy cannot express. The result was not a looser
 * policy on that page but a broken one.
 */
final class SecurityHeadersMiddlewareCspTest extends TestCase
{
    private function middleware(array $cspConfig = null): SecurityHeadersMiddleware
    {
        return new SecurityHeadersMiddleware(
            ['x_content_type_nosniff' => true],
            $cspConfig === null ? null : new ContentSecurityPolicyBuilder($cspConfig),
        );
    }

    private function strictConfig(): array
    {
        return [
            'default_src' => ["'none'"],
            'script_src'  => ["'none'"],
            'report_only' => false,
        ];
    }

    public function test_global_policy_applies_when_the_route_set_none(): void
    {
        $response = $this->middleware($this->strictConfig())
            ->handle(new Request(), fn () => new Response());

        $this->assertStringContainsString("default-src 'none'", (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_a_route_that_set_its_own_policy_keeps_it(): void
    {
        $routePolicy = "default-src 'self'; script-src 'self' 'nonce-abc123'";

        $response = $this->middleware($this->strictConfig())->handle(
            new Request(),
            function () use ($routePolicy) {
                $r = new Response();
                $r->headers->set('Content-Security-Policy', $routePolicy);
                return $r;
            },
        );

        $this->assertSame($routePolicy, $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('nonce-abc123', (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_other_security_headers_still_apply_to_that_route(): void
    {
        // Deferring on CSP must not turn the whole middleware off — the route
        // opted out of one header, not all of them.
        $response = $this->middleware($this->strictConfig())->handle(
            new Request(),
            function () {
                $r = new Response();
                $r->headers->set('Content-Security-Policy', "default-src 'self'");
                return $r;
            },
        );

        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_report_only_default_does_not_defer_to_an_enforcing_route_header(): void
    {
        // Different header names are different questions. A route enforcing a
        // policy says nothing about whether the app wants a report-only one.
        $response = $this->middleware([...$this->strictConfig(), 'report_only' => true])->handle(
            new Request(),
            function () {
                $r = new Response();
                $r->headers->set('Content-Security-Policy', "default-src 'self'");
                return $r;
            },
        );

        $this->assertNotNull($response->headers->get('Content-Security-Policy-Report-Only'));
        $this->assertSame("default-src 'self'", $response->headers->get('Content-Security-Policy'));
    }

    public function test_no_csp_configured_leaves_the_route_header_alone(): void
    {
        $response = $this->middleware(null)->handle(
            new Request(),
            function () {
                $r = new Response();
                $r->headers->set('Content-Security-Policy', "default-src 'self'");
                return $r;
            },
        );

        $this->assertSame("default-src 'self'", $response->headers->get('Content-Security-Policy'));
    }
}
