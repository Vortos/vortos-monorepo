<?php

declare(strict_types=1);

namespace Vortos\Auth\Tests\Http;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Vortos\Auth\DependencyInjection\RefreshCookieConfig;
use Vortos\Auth\Http\RefreshTokenCookie;

final class RefreshTokenCookieTest extends TestCase
{
    private function cookie(bool $enabled = true): RefreshTokenCookie
    {
        return new RefreshTokenCookie(
            enabled:  $enabled,
            name:     'sq_rt',
            path:     '/api/auth/refresh',
            domain:   null,
            sameSite: Cookie::SAMESITE_LAX,
            secure:   true,
            ttl:      604800,
        );
    }

    /** @return Cookie[] */
    private function cookiesOn(Response $response): array
    {
        return $response->headers->getCookies();
    }

    public function test_attached_cookie_is_httponly_secure_and_scoped(): void
    {
        $response = new Response();
        $this->cookie()->attach($response, 'the-refresh-token');

        $set = $this->cookiesOn($response);
        $this->assertCount(1, $set);

        $c = $set[0];
        $this->assertSame('sq_rt', $c->getName());
        $this->assertSame('the-refresh-token', $c->getValue());

        // The three attributes that make this worth doing at all.
        $this->assertTrue($c->isHttpOnly(), 'refresh cookie must be httpOnly or XSS can read it');
        $this->assertTrue($c->isSecure(), 'refresh cookie must be Secure');
        $this->assertSame(Cookie::SAMESITE_LAX, $c->getSameSite());

        // Scoped to the refresh endpoint, not the whole API.
        $this->assertSame('/api/auth/refresh', $c->getPath());
    }

    public function test_read_returns_the_presented_token(): void
    {
        $request = new Request();
        $request->cookies->set('sq_rt', 'presented');

        $this->assertSame('presented', $this->cookie()->read($request));
    }

    public function test_read_returns_null_when_absent_or_empty(): void
    {
        $this->assertNull($this->cookie()->read(new Request()));

        $empty = new Request();
        $empty->cookies->set('sq_rt', '');
        $this->assertNull($this->cookie()->read($empty));
    }

    public function test_clear_expires_the_cookie_with_matching_attributes(): void
    {
        $response = new Response();
        $this->cookie()->clear($response);

        $c = $this->cookiesOn($response)[0];

        $this->assertSame('sq_rt', $c->getName());
        $this->assertLessThan(time(), $c->getExpiresTime(), 'cleared cookie must be in the past');

        // A deletion whose path/domain/sameSite differ from the original is a
        // DIFFERENT cookie — the browser keeps the real one and logout silently
        // does nothing. These assertions are the regression guard for that.
        $this->assertSame('/api/auth/refresh', $c->getPath());
        $this->assertSame(Cookie::SAMESITE_LAX, $c->getSameSite());
        $this->assertTrue($c->isHttpOnly());
        $this->assertTrue($c->isSecure());
    }

    public function test_disabled_transport_is_inert_in_both_directions(): void
    {
        $disabled = $this->cookie(enabled: false);

        $response = new Response();
        $disabled->attach($response, 'token');
        $disabled->clear($response);
        $this->assertSame([], $this->cookiesOn($response));

        $request = new Request();
        $request->cookies->set('sq_rt', 'present-but-ignored');
        $this->assertNull($disabled->read($request));
    }

    public function test_samesite_none_without_secure_is_rejected(): void
    {
        // Browsers drop this combination silently, which surfaces as "users get
        // logged out at random" rather than as a config error. Fail loudly here.
        $this->expectException(\InvalidArgumentException::class);

        (new RefreshCookieConfig())->secure(false)->sameSite(Cookie::SAMESITE_NONE);
    }

    public function test_secure_false_after_samesite_none_is_also_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new RefreshCookieConfig())->sameSite(Cookie::SAMESITE_NONE)->secure(false);
    }

    public function test_unknown_samesite_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new RefreshCookieConfig())->sameSite('sometimes');
    }

    public function test_config_defaults_are_off_and_safe(): void
    {
        $defaults = (new RefreshCookieConfig())->toArray();

        $this->assertFalse($defaults['enabled'], 'must not change transport on a framework upgrade');
        $this->assertTrue($defaults['secure']);
        $this->assertSame(Cookie::SAMESITE_LAX, $defaults['same_site']);
    }

    public function test_with_name_derives_a_per_client_cookie_keeping_every_other_attribute(): void
    {
        // Per-client cookies must agree on path/domain/sameSite/secure/ttl/enabled and differ
        // ONLY by name, so two first-party SPAs on one API keep separate sessions in one browser
        // and each can still clear its own (a mismatch would leave a session cookie undeletable).
        $base    = $this->cookie();
        $derived = $base->withName('sqadmin_rt');

        $this->assertSame('sqadmin_rt', $derived->name());

        $onBase = new Response();
        $onDerived = new Response();
        $base->attach($onBase, 't1');
        $derived->attach($onDerived, 't2');

        $b = $this->cookiesOn($onBase)[0];
        $d = $this->cookiesOn($onDerived)[0];

        $this->assertSame('sq_rt', $b->getName());
        $this->assertSame('sqadmin_rt', $d->getName());
        $this->assertSame($b->getPath(), $d->getPath());
        $this->assertSame($b->getDomain(), $d->getDomain());
        $this->assertSame($b->getSameSite(), $d->getSameSite());
        $this->assertSame($b->isSecure(), $d->isSecure());
        $this->assertTrue($d->isHttpOnly());
    }

    public function test_with_name_on_a_disabled_transport_stays_inert(): void
    {
        $derived = $this->cookie(enabled: false)->withName('sqadmin_rt');

        $response = new Response();
        $derived->attach($response, 'token');

        $this->assertCount(0, $this->cookiesOn($response));
        $this->assertNull($derived->read(Request::create('/', 'POST', [], ['sqadmin_rt' => 'x'])));
    }
}
