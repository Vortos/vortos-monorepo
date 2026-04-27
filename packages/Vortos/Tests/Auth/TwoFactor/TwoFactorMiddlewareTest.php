<?php
declare(strict_types=1);

namespace Vortos\Tests\Auth\TwoFactor;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Auth\Identity\AnonymousIdentity;
use Vortos\Auth\TwoFactor\Contract\TwoFactorVerifierInterface;
use Vortos\Auth\TwoFactor\Middleware\TwoFactorMiddleware;
use Vortos\Cache\Adapter\ArrayAdapter;

final class TwoFactorMiddlewareTest extends TestCase
{
    private function makeProvider(bool $authenticated = true): CurrentUserProvider
    {
        $adapter = new ArrayAdapter();
        $identity = $authenticated ? new UserIdentity('user-1', []) : new AnonymousIdentity();
        $adapter->set('auth:identity', $identity);
        return new CurrentUserProvider($adapter);
    }

    private function makeEvent(string $controller): RequestEvent
    {
        $request = Request::create('/test');
        $request->attributes->set('_controller', $controller);
        $kernel = $this->createMock(HttpKernelInterface::class);
        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    public function test_allows_when_no_verifier(): void
    {
        $middleware = new TwoFactorMiddleware($this->makeProvider(), null, ['App\TestCtrl']);
        $event = $this->makeEvent('App\TestCtrl');
        $middleware->onKernelRequest($event);
        $this->assertNull($event->getResponse());
    }

    public function test_allows_when_controller_not_protected(): void
    {
        $verifier = $this->createMock(TwoFactorVerifierInterface::class);
        $verifier->expects($this->never())->method('isVerified');

        $middleware = new TwoFactorMiddleware($this->makeProvider(), $verifier, []);
        $event = $this->makeEvent('App\TestCtrl');
        $middleware->onKernelRequest($event);
        $this->assertNull($event->getResponse());
    }

    public function test_allows_when_2fa_verified(): void
    {
        $verifier = $this->createMock(TwoFactorVerifierInterface::class);
        $verifier->method('isVerified')->willReturn(true);

        $middleware = new TwoFactorMiddleware($this->makeProvider(), $verifier, ['App\TestCtrl']);
        $event = $this->makeEvent('App\TestCtrl');
        $middleware->onKernelRequest($event);
        $this->assertNull($event->getResponse());
    }

    public function test_denies_with_403_when_2fa_not_verified(): void
    {
        $verifier = $this->createMock(TwoFactorVerifierInterface::class);
        $verifier->method('isVerified')->willReturn(false);
        $verifier->method('getChallengeUrl')->willReturn('/auth/2fa/challenge');

        $middleware = new TwoFactorMiddleware($this->makeProvider(), $verifier, ['App\TestCtrl']);
        $event = $this->makeEvent('App\TestCtrl');
        $middleware->onKernelRequest($event);
        $this->assertSame(403, $event->getResponse()->getStatusCode());
    }

    public function test_response_contains_challenge_url(): void
    {
        $verifier = $this->createMock(TwoFactorVerifierInterface::class);
        $verifier->method('isVerified')->willReturn(false);
        $verifier->method('getChallengeUrl')->willReturn('/auth/2fa/challenge');

        $middleware = new TwoFactorMiddleware($this->makeProvider(), $verifier, ['App\TestCtrl']);
        $event = $this->makeEvent('App\TestCtrl');
        $middleware->onKernelRequest($event);
        $body = json_decode($event->getResponse()->getContent(), true);
        $this->assertSame('/auth/2fa/challenge', $body['challenge_url']);
    }

    public function test_skips_anonymous_users(): void
    {
        $verifier = $this->createMock(TwoFactorVerifierInterface::class);
        $verifier->expects($this->never())->method('isVerified');

        $middleware = new TwoFactorMiddleware($this->makeProvider(false), $verifier, ['App\TestCtrl']);
        $event = $this->makeEvent('App\TestCtrl');
        $middleware->onKernelRequest($event);
        $this->assertNull($event->getResponse());
    }
}
