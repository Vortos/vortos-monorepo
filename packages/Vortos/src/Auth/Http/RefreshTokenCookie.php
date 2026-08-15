<?php

declare(strict_types=1);

namespace Vortos\Auth\Http;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Carries the refresh token in an httpOnly cookie instead of a response body.
 *
 * ## Why this exists
 *
 * An access token is short-lived and can live in a JavaScript variable: steal it
 * and you have fifteen minutes. A refresh token is the opposite — it is the
 * session. Handing it to the browser as JSON means the SPA must put it somewhere
 * JavaScript can reach, and `localStorage` is where it ends up. That turns any
 * XSS, anywhere on the origin, from a fifteen-minute problem into a standing one:
 * the attacker reads the token and rotates it forever, and revoking the user's
 * session does not help because they simply refresh again.
 *
 * `httpOnly` is the only mechanism that takes the token out of script's reach
 * entirely. The cost is that a cookie is ambient — the browser attaches it to
 * requests the user did not intend — which is why the attributes below are not
 * optional decoration.
 *
 * ## The attributes, and why each one is load-bearing
 *
 * - **httpOnly** — the entire point. Script cannot read it, so XSS cannot lift it.
 * - **secure** — never sent over plaintext. Off only for `http://localhost` dev.
 * - **path** — scoped to the refresh endpoint, so the cookie is not attached to
 *   every API call. A credential that travels on requests that do not need it is
 *   a credential with a larger blast radius than it needs.
 * - **sameSite** — `Lax` is correct when the SPA and the API are sibling
 *   subdomains of one registrable domain (app.example.com → api.example.com is
 *   *same-site* even though it is cross-origin), and it is what keeps a genuinely
 *   third-party page from driving the refresh endpoint. `None` is accepted for
 *   deployments where the two are on unrelated domains, and is only safe with
 *   CSRF protection on the endpoint — see below.
 * - **domain** — set to the shared parent (`.example.com`) only when the SPA and
 *   API are on different subdomains. Null keeps it host-only, which is tighter.
 *
 * ## CSRF
 *
 * Moving the refresh token into a cookie makes the refresh endpoint
 * cookie-authenticated, and cookie-authenticated endpoints are CSRF-reachable.
 * A Bearer-only API is structurally immune and often skips CSRF for that reason;
 * that exemption must NOT extend to this endpoint. Keep CSRF protection on the
 * refresh route, or the ambient cookie hands back exactly what `httpOnly` bought.
 */
final class RefreshTokenCookie
{
    /**
     * @param bool        $enabled  When false, `read()` returns null and the writers
     *                              are no-ops, so an application can be migrated (or
     *                              rolled back) with a config flag rather than a deploy.
     * @param string      $name     Cookie name.
     * @param string      $path     Path scope — the refresh endpoint, not '/'.
     * @param string|null $domain   Shared parent domain, or null for host-only.
     * @param string      $sameSite One of Cookie::SAMESITE_*.
     * @param bool        $secure   HTTPS-only.
     * @param int         $ttl      Lifetime in seconds; should match the refresh token TTL.
     */
    public function __construct(
        private readonly bool    $enabled  = false,
        private readonly string  $name     = 'vortos_rt',
        private readonly string  $path     = '/',
        private readonly ?string $domain   = null,
        private readonly string  $sameSite = Cookie::SAMESITE_LAX,
        private readonly bool    $secure   = true,
        private readonly int     $ttl      = 604800,
    ) {}

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * The refresh token the browser presented, or null.
     *
     * Returns null rather than throwing when the cookie is absent: a first login,
     * a cleared jar and an expired cookie are all ordinary, and the caller's own
     * "no refresh token" path already handles them.
     */
    public function read(Request $request): ?string
    {
        if (!$this->enabled) {
            return null;
        }

        $value = $request->cookies->get($this->name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Attach a freshly issued (or rotated) refresh token.
     *
     * Called on login, on 2FA completion, and on every rotation — a rotating
     * refresh token that is not re-attached logs the user out at the next refresh.
     */
    public function attach(Response $response, string $refreshToken): void
    {
        if (!$this->enabled) {
            return;
        }

        $response->headers->setCookie($this->build($refreshToken, time() + $this->ttl));
    }

    /**
     * Expire the cookie on logout.
     *
     * Uses the same name/path/domain as `attach()`; a mismatch on any of the three
     * leaves the original cookie in place and the "logout" only appears to work.
     */
    public function clear(Response $response): void
    {
        if (!$this->enabled) {
            return;
        }

        // Symfony's clearCookie does not carry sameSite/secure, and a Set-Cookie
        // whose attributes disagree with the original can be treated as a
        // different cookie. Build the deletion the same way it was written.
        $response->headers->setCookie($this->build('', 1));
    }

    private function build(string $value, int $expiresAt): Cookie
    {
        return Cookie::create(
            name:     $this->name,
            value:    $value,
            expire:   $expiresAt,
            path:     $this->path,
            domain:   $this->domain,
            secure:   $this->secure,
            httpOnly: true,
            raw:      false,
            sameSite: $this->sameSite,
        );
    }
}
