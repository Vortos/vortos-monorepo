<?php

declare(strict_types=1);

namespace Vortos\FeatureFlagsAdmin\Tests\Security;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Auth\Identity\AnonymousIdentity;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Auth\TwoFactor\Contract\TwoFactorVerifierInterface;
use Vortos\Cache\Adapter\ArrayAdapter;
use Vortos\FeatureFlagsAdmin\AdminConfig;
use Vortos\FeatureFlagsAdmin\Http\Middleware\AdminAuthMiddleware;
use Vortos\Http\Exception\ForbiddenException;
use Vortos\Http\Request;
use Vortos\Http\Response;

final class AdminAuthMiddlewareTest extends TestCase
{
    public function test_anonymous_user_redirected_to_login(): void
    {
        $middleware = $this->buildMiddleware(null);
        $request = Request::create('/admin/flags');

        $response = $middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/login', $response->headers->get('Location'));
        $this->assertStringContainsString('redirect=', $response->headers->get('Location'));
    }

    public function test_authenticated_admin_passes_through(): void
    {
        $middleware = $this->buildMiddleware(new UserIdentity('admin-1', ['ROLE_ADMIN']));
        $request = Request::create('/admin/flags');

        $response = $middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_authenticated_non_admin_gets_403(): void
    {
        $middleware = $this->buildMiddleware(new UserIdentity('user-1', ['ROLE_USER']));
        $request = Request::create('/admin/flags');

        $this->expectException(ForbiddenException::class);
        $middleware->handle($request, fn() => new Response('ok'));
    }

    public function test_non_admin_routes_pass_through(): void
    {
        $middleware = $this->buildMiddleware(null);
        $request = Request::create('/api/flags');

        $response = $middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_subpath_is_protected(): void
    {
        $middleware = $this->buildMiddleware(null);
        $request = Request::create('/admin/flags/detail/my-flag');

        $response = $middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(302, $response->getStatusCode());
    }

    public function test_redirect_preserves_original_path(): void
    {
        $middleware = $this->buildMiddleware(null);
        $request = Request::create('/admin/flags/detail/my-flag');

        $response = $middleware->handle($request, fn() => new Response('ok'));

        $this->assertStringContainsString(
            urlencode('/admin/flags/detail/my-flag'),
            $response->headers->get('Location'),
        );
    }

    public function test_custom_required_role(): void
    {
        $config = new AdminConfig(true, '/admin/flags', 'ROLE_FLAGS_ADMIN');
        $adapter = new ArrayAdapter();
        $adapter->set('auth:identity', new UserIdentity('user-1', ['ROLE_ADMIN']));
        $middleware = new AdminAuthMiddleware(new CurrentUserProvider($adapter), $config);

        $request = Request::create('/admin/flags');

        $this->expectException(ForbiddenException::class);
        $middleware->handle($request, fn() => new Response('ok'));
    }

    public function test_require_2fa_denies_when_no_verifier_configured(): void
    {
        // Fail-closed: 2FA required but no verifier wired ⇒ Forbidden, never a silent pass.
        $adapter = new ArrayAdapter();
        $adapter->set('auth:identity', new UserIdentity('admin-1', ['ROLE_ADMIN']));
        $middleware = new AdminAuthMiddleware(
            new CurrentUserProvider($adapter),
            new AdminConfig(true, '/admin/flags', 'ROLE_ADMIN', true),
            null,
        );

        $this->expectException(ForbiddenException::class);
        $middleware->handle(Request::create('/admin/flags'), fn() => new Response('ok'));
    }

    public function test_require_2fa_redirects_to_challenge_when_not_verified(): void
    {
        $adapter = new ArrayAdapter();
        $adapter->set('auth:identity', new UserIdentity('admin-1', ['ROLE_ADMIN']));
        $middleware = new AdminAuthMiddleware(
            new CurrentUserProvider($adapter),
            new AdminConfig(true, '/admin/flags', 'ROLE_ADMIN', true),
            $this->verifier(false, '/2fa/challenge'),
        );

        $response = $middleware->handle(Request::create('/admin/flags/detail/x'), fn() => new Response('ok'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/2fa/challenge', $response->headers->get('Location'));
        $this->assertStringContainsString(urlencode('/admin/flags/detail/x'), $response->headers->get('Location'));
    }

    public function test_require_2fa_passes_when_verified(): void
    {
        $adapter = new ArrayAdapter();
        $adapter->set('auth:identity', new UserIdentity('admin-1', ['ROLE_ADMIN']));
        $middleware = new AdminAuthMiddleware(
            new CurrentUserProvider($adapter),
            new AdminConfig(true, '/admin/flags', 'ROLE_ADMIN', true),
            $this->verifier(true, '/2fa/challenge'),
        );

        $response = $middleware->handle(Request::create('/admin/flags'), fn() => new Response('ok'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_require_2fa_enforced_after_role_check(): void
    {
        // A subject lacking the required role is rejected before 2FA is ever consulted.
        $adapter = new ArrayAdapter();
        $adapter->set('auth:identity', new UserIdentity('user-1', ['ROLE_USER']));
        $middleware = new AdminAuthMiddleware(
            new CurrentUserProvider($adapter),
            new AdminConfig(true, '/admin/flags', 'ROLE_ADMIN', true),
            $this->verifier(true, '/2fa/challenge'),
        );

        $this->expectException(ForbiddenException::class);
        $middleware->handle(Request::create('/admin/flags'), fn() => new Response('ok'));
    }

    private function verifier(bool $verified, string $challengeUrl): TwoFactorVerifierInterface
    {
        return new class ($verified, $challengeUrl) implements TwoFactorVerifierInterface {
            public function __construct(private bool $verified, private string $challengeUrl) {}

            public function isVerified(UserIdentityInterface $identity, Request $request): bool
            {
                return $this->verified;
            }

            public function getChallengeUrl(): string
            {
                return $this->challengeUrl;
            }
        };
    }

    private function buildMiddleware(?UserIdentity $user): AdminAuthMiddleware
    {
        $adapter = new ArrayAdapter();
        if ($user !== null) {
            $adapter->set('auth:identity', $user);
        }

        return new AdminAuthMiddleware(
            new CurrentUserProvider($adapter),
            new AdminConfig(),
        );
    }
}
