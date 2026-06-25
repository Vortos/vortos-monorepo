<?php

declare(strict_types=1);

namespace Vortos\Auth\Tests\RateLimit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Auth\Identity\AnonymousIdentity;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\RateLimit\Contract\RateLimitPolicyInterface;
use Vortos\Auth\RateLimit\Contract\RateLimitStoreInterface;
use Vortos\Auth\RateLimit\RateLimitRule;
use Vortos\Auth\RateLimit\RateLimitScope;
use Vortos\Auth\RateLimit\RateLimitService;
use Vortos\Cache\Adapter\ArrayAdapter;
use Vortos\Http\Contract\IpResolverInterface;
use Vortos\Http\Request;

final class RateLimitServiceIpResolverTest extends TestCase
{
    private const CONTROLLER = 'App\\Controller\\LoginController';
    private const POLICY = 'App\\Policy\\LoginPolicy';

    public function test_ip_scope_uses_ip_resolver_when_available(): void
    {
        $capturedKey = null;

        $store = $this->createMock(RateLimitStoreInterface::class);
        $store->method('increment')->willReturnCallback(function (string $key) use (&$capturedKey) {
            $capturedKey = $key;
            return 1;
        });
        $store->method('getTtl')->willReturn(60);

        $resolver = new class implements IpResolverInterface {
            public function resolve(SymfonyRequest $request): string
            {
                return '203.0.113.42';
            }
        };

        $service = $this->buildService($store, $resolver);

        $request = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '10.0.0.1']);
        $request->attributes->set('_controller', self::CONTROLLER . '::login');

        $service->enforce($request, RateLimitScope::Ip);

        $this->assertNotNull($capturedKey);
        $this->assertStringContainsString('203.0.113.42', $capturedKey);
        $this->assertStringNotContainsString('10.0.0.1', $capturedKey);
    }

    public function test_default_resolver_uses_remote_addr(): void
    {
        $capturedKey = null;

        $store = $this->createMock(RateLimitStoreInterface::class);
        $store->method('increment')->willReturnCallback(function (string $key) use (&$capturedKey) {
            $capturedKey = $key;
            return 1;
        });
        $store->method('getTtl')->willReturn(60);

        $service = $this->buildService($store, new \Vortos\Http\IpResolver\RemoteAddrIpResolver());

        $request = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '10.0.0.1']);
        $request->attributes->set('_controller', self::CONTROLLER . '::login');

        $service->enforce($request, RateLimitScope::Ip);

        $this->assertNotNull($capturedKey);
        $this->assertStringContainsString('10.0.0.1', $capturedKey);
    }

    public function test_user_scope_not_affected_by_ip_resolver(): void
    {
        $capturedKey = null;

        $store = $this->createMock(RateLimitStoreInterface::class);
        $store->method('increment')->willReturnCallback(function (string $key) use (&$capturedKey) {
            $capturedKey = $key;
            return 1;
        });
        $store->method('getTtl')->willReturn(60);

        $resolver = new class implements IpResolverInterface {
            public function resolve(SymfonyRequest $request): string
            {
                return '203.0.113.42';
            }
        };

        $service = $this->buildService($store, $resolver);

        $request = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '10.0.0.1']);
        $request->attributes->set('_controller', self::CONTROLLER . '::login');

        $service->enforce($request, RateLimitScope::User);

        $this->assertNotNull($capturedKey);
        $this->assertStringStartsWith('rl:user:', $capturedKey);
        $this->assertStringNotContainsString('203.0.113.42', $capturedKey);
    }

    private function buildService(RateLimitStoreInterface $store, IpResolverInterface $resolver): RateLimitService
    {
        $policy = new class implements RateLimitPolicyInterface {
            public function getLimit(UserIdentityInterface $identity): RateLimitRule
            {
                return new RateLimitRule(10, 60);
            }
        };

        return new RateLimitService(
            currentUser: new CurrentUserProvider(new ArrayAdapter()),
            store: $store,
            routeMap: [
                self::CONTROLLER => [
                    ['policy' => self::POLICY, 'per' => RateLimitScope::Ip],
                    ['policy' => self::POLICY, 'per' => RateLimitScope::User],
                ],
            ],
            policies: [self::POLICY => $policy],
            ipResolver: $resolver,
        );
    }
}
