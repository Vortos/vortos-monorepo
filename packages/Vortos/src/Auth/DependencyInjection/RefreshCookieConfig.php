<?php

declare(strict_types=1);

namespace Vortos\Auth\DependencyInjection;

use Symfony\Component\HttpFoundation\Cookie;

/**
 * Delivery of the refresh token as an httpOnly cookie rather than a JSON field.
 *
 * See {@see \Vortos\Auth\Http\RefreshTokenCookie} for why this is worth doing and
 * what each attribute protects. This class is the configuration surface only.
 *
 * Disabled by default: turning it on changes the wire contract with every client,
 * and an application that has not moved its SPA over yet must not have the
 * transport swapped underneath it by a framework upgrade.
 */
final class RefreshCookieConfig
{
    private bool    $enabled  = false;
    private string  $name     = 'vortos_rt';
    private string  $path     = '/';
    private ?string $domain   = null;
    private string  $sameSite = Cookie::SAMESITE_LAX;
    private bool    $secure   = true;
    private ?int    $ttl      = null;

    public function enabled(bool $enabled = true): static
    {
        $this->enabled = $enabled;
        return $this;
    }

    public function name(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Path scope. Set this to the refresh endpoint (e.g. `/api/auth/refresh`), not
     * `/` — a session credential attached to every API call is a session credential
     * exposed on every API call.
     */
    public function path(string $path): static
    {
        $this->path = $path;
        return $this;
    }

    /**
     * Shared parent domain, e.g. `.example.com`, when the SPA and API are on
     * different subdomains. Leave null for a host-only cookie, which is tighter
     * and correct when they share an origin.
     */
    public function domain(?string $domain): static
    {
        $this->domain = $domain;
        return $this;
    }

    /**
     * `Lax` (default) is right whenever the SPA and API share a registrable domain.
     * `None` is for genuinely cross-site deployments and is only safe with CSRF
     * protection on the refresh endpoint.
     */
    public function sameSite(string $sameSite): static
    {
        $allowed = [Cookie::SAMESITE_LAX, Cookie::SAMESITE_STRICT, Cookie::SAMESITE_NONE];

        if (!in_array($sameSite, $allowed, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Refresh cookie sameSite must be one of %s, got "%s".',
                implode(', ', $allowed),
                $sameSite,
            ));
        }

        // SameSite=None without Secure is rejected by every current browser, so the
        // cookie would simply never be stored — a failure that shows up as "users
        // are logged out at random" rather than as a configuration error.
        if ($sameSite === Cookie::SAMESITE_NONE && !$this->secure) {
            throw new \InvalidArgumentException(
                'Refresh cookie sameSite=None requires secure=true; browsers reject the combination otherwise.'
            );
        }

        $this->sameSite = $sameSite;
        return $this;
    }

    /** HTTPS-only. Turn off for `http://localhost` development and nowhere else. */
    public function secure(bool $secure = true): static
    {
        if (!$secure && $this->sameSite === Cookie::SAMESITE_NONE) {
            throw new \InvalidArgumentException(
                'Refresh cookie sameSite=None requires secure=true; browsers reject the combination otherwise.'
            );
        }

        $this->secure = $secure;
        return $this;
    }

    /**
     * Cookie lifetime in seconds. Defaults to the configured refresh-token TTL,
     * which is almost always what you want — a cookie that outlives its token
     * produces a failed refresh, and one that dies first logs the user out early.
     */
    public function ttl(int $seconds): static
    {
        $this->ttl = max(0, $seconds);
        return $this;
    }

    /** @return array{enabled: bool, name: string, path: string, domain: string|null, same_site: string, secure: bool, ttl: int|null} */
    public function toArray(): array
    {
        return [
            'enabled'   => $this->enabled,
            'name'      => $this->name,
            'path'      => $this->path,
            'domain'    => $this->domain,
            'same_site' => $this->sameSite,
            'secure'    => $this->secure,
            'ttl'       => $this->ttl,
        ];
    }
}
