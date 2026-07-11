<?php

declare(strict_types=1);

namespace Vortos\Security\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Vortos\Security\Csrf\CsrfTokenService;

final class CsrfTokenServiceTest extends TestCase
{
    private function issuedCookie(Response $response): Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'csrf_token') {
                return $cookie;
            }
        }
        self::fail('csrf_token cookie was not issued.');
    }

    public function test_cookie_is_host_only_by_default(): void
    {
        // Backward compatible: 5-arg construction (no domain) => host-only cookie.
        $service = new CsrfTokenService('csrf_token', 'X-CSRF-Token', 32, false, 'Strict');

        $response = new Response();
        $service->issue($response);

        self::assertNull($this->issuedCookie($response)->getDomain());
    }

    public function test_cookie_domain_is_applied_when_configured(): void
    {
        // Split frontend/backend origins: share the cookie across sibling subdomains.
        $service = new CsrfTokenService('csrf_token', 'X-CSRF-Token', 32, true, 'Lax', '.example.com');

        $response = new Response();
        $service->issue($response);

        $cookie = $this->issuedCookie($response);
        self::assertSame('.example.com', $cookie->getDomain());
        self::assertTrue($cookie->isSecure());
        self::assertSame('lax', strtolower((string) $cookie->getSameSite()));
        self::assertFalse($cookie->isHttpOnly(), 'JS must read the cookie for double-submit.');
    }
}
