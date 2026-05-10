<?php

declare(strict_types=1);

namespace Vortos\Security\Csrf;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Manages CSRF tokens using the double-submit cookie pattern.
 *
 * ## How it works
 *
 * 1. On the first request (no cookie present), a cryptographically random token
 *    is generated and set in both a SameSite cookie and exposed to the client
 *    via a response header (X-CSRF-Token-Set) so JS can read it.
 *
 * 2. On subsequent state-changing requests (POST/PUT/PATCH/DELETE), the client
 *    must echo the cookie value in the X-CSRF-Token request header.
 *
 * 3. validate() compares the cookie value with the header value using
 *    hash_equals() (constant-time) to prevent timing attacks.
 *
 * ## Why double-submit?
 *
 * Double-submit cookie requires no server-side session storage — compatible with
 * stateless JWT auth. An attacker can forge a request but cannot read the cookie
 * (SameSite + HttpOnly prevents cross-site cookie access) and thus cannot echo
 * the correct value in the header.
 */
final class CsrfTokenService
{
    public function __construct(
        private readonly string $cookieName,
        private readonly string $headerName,
        private readonly int    $tokenLength,
        private readonly bool   $cookieSecure,
        private readonly string $cookieSameSite,
    ) {}

    /**
     * Returns true when the request carries a valid CSRF token.
     * Exempt methods (GET, HEAD, OPTIONS) always pass.
     */
    public function validate(Request $request): bool
    {
        if (in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS', 'TRACE'], true)) {
            return true;
        }

        $cookie = $request->cookies->get($this->cookieName, '');
        $header = $request->headers->get($this->headerName, '');

        if ($cookie === '' || $header === '') {
            return false;
        }

        return hash_equals($cookie, $header);
    }

    /**
     * Generates a fresh token and attaches the cookie to the response.
     * Called when no cookie is present on the incoming request.
     */
    public function issue(Response $response): string
    {
        $token = bin2hex(random_bytes($this->tokenLength));

        $response->headers->setCookie(
            new Cookie(
                name:     $this->cookieName,
                value:    $token,
                expire:   0,             // session cookie
                path:     '/',
                domain:   null,
                secure:   $this->cookieSecure,
                httpOnly: false,         // JS must be able to read it for double-submit
                raw:      false,
                sameSite: $this->cookieSameSite,
            )
        );

        // Expose the token value so JS frameworks can pick it up on first load
        $response->headers->set('X-CSRF-Token-Set', $token);

        return $token;
    }

    /**
     * Returns true when the request already has a CSRF cookie.
     */
    public function hasCookie(Request $request): bool
    {
        return $request->cookies->has($this->cookieName);
    }

    public function cookieName(): string
    {
        return $this->cookieName;
    }

    public function headerName(): string
    {
        return $this->headerName;
    }
}
