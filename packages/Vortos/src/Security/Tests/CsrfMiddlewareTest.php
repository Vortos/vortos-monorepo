<?php

declare(strict_types=1);

namespace Vortos\Security\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Vortos\Http\Attribute\AsMiddleware;
use Vortos\Http\MiddlewareOrder;
use Vortos\Http\Request;
use Vortos\Security\Csrf\CsrfTokenService;
use Vortos\Security\Csrf\Middleware\CsrfMiddleware;
use Vortos\Security\Event\SecurityEventDispatcher;

final class CsrfMiddlewareTest extends TestCase
{
    public function test_csrf_middleware_runs_at_csrf_order(): void
    {
        $attrs = (new \ReflectionClass(CsrfMiddleware::class))->getAttributes(AsMiddleware::class);
        $this->assertNotEmpty($attrs);
        $this->assertSame(MiddlewareOrder::CSRF, $attrs[0]->newInstance()->order);
    }

    public function test_bearer_authenticated_request_skips_csrf_when_enabled(): void
    {
        // Stateless token-auth request: no CSRF cookie/header at all, but carries a Bearer.
        $request = Request::create('/api/thing', 'POST');
        $request->headers->set('Authorization', 'Bearer some.jwt.token');

        $middleware = $this->makeMiddleware(skipWhenBearerAuth: true);

        $passed = false;
        $response = $middleware->handle($request, function () use (&$passed) {
            $passed = true;
            return new Response('ok', 200);
        });

        $this->assertTrue($passed, 'Bearer request must bypass CSRF and reach the handler.');
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_bearer_request_is_still_enforced_when_flag_disabled(): void
    {
        // Default behaviour (flag off): Bearer presence must NOT weaken CSRF.
        $request = Request::create('/api/thing', 'POST');
        $request->headers->set('Authorization', 'Bearer some.jwt.token');

        $middleware = $this->makeMiddleware(skipWhenBearerAuth: false);

        $response = $middleware->handle($request, fn () => new Response('ok', 200));

        $this->assertSame(403, $response->getStatusCode(), 'Missing CSRF token must still be rejected.');
    }

    public function test_non_bearer_request_is_still_enforced_when_flag_enabled(): void
    {
        // Cookie/session-style request (no Authorization header) must still require CSRF
        // even when the Bearer-skip policy is on.
        $request = Request::create('/api/thing', 'POST');

        $middleware = $this->makeMiddleware(skipWhenBearerAuth: true);

        $response = $middleware->handle($request, fn () => new Response('ok', 200));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_valid_double_submit_still_passes(): void
    {
        // Sanity: the classic double-submit path is unaffected by the new flag.
        $request = Request::create('/api/thing', 'POST', server: ['HTTP_X_CSRF_TOKEN' => 'tok123']);
        $request->cookies->set('csrf_token', 'tok123');

        $middleware = $this->makeMiddleware(skipWhenBearerAuth: true);

        $passed = false;
        $middleware->handle($request, function () use (&$passed) {
            $passed = true;
            return new Response('ok', 200);
        });

        $this->assertTrue($passed);
    }

    private function makeMiddleware(bool $skipWhenBearerAuth): CsrfMiddleware
    {
        return new CsrfMiddleware(
            csrf: new CsrfTokenService('csrf_token', 'X-CSRF-Token', 32, false, 'Strict'),
            events: new SecurityEventDispatcher(null, null),
            enabled: true,
            skipControllers: [],
            skipWhenBearerAuth: $skipWhenBearerAuth,
        );
    }
}
