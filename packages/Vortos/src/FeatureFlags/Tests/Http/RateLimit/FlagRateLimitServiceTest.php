<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Http\RateLimit;

use PHPUnit\Framework\TestCase;
use Vortos\Cache\Contract\AtomicCacheInterface;
use Vortos\FeatureFlags\Http\Exception\TooManyRequestsException;
use Vortos\FeatureFlags\Http\RateLimit\FlagRateLimitService;

final class FlagRateLimitServiceTest extends TestCase
{
    public function test_under_limit_does_not_throw(): void
    {
        $cache = $this->createMock(AtomicCacheInterface::class);
        $cache->method('get')->willReturn(null);
        $cache->method('setNx')->willReturn(true);
        $cache->method('get')->willReturn(1);

        $service = new FlagRateLimitService($cache);

        $this->expectNotToPerformAssertions();
        $service->checkManagement('user1');
    }

    public function test_over_management_limit_throws(): void
    {
        $cache = $this->createMock(AtomicCacheInterface::class);
        // Simulate already at 121 calls
        $cache->method('get')->willReturn(121);
        $cache->method('set')->willReturn(true);

        $service = new FlagRateLimitService($cache);

        $this->expectException(TooManyRequestsException::class);
        $service->checkManagement('user1');
    }

    public function test_different_users_have_independent_counters(): void
    {
        $counts = [];
        $cache  = $this->createMock(AtomicCacheInterface::class);
        $cache->method('get')->willReturnCallback(function (string $key) use (&$counts) {
            return $counts[$key] ?? null;
        });
        $cache->method('setNx')->willReturnCallback(function (string $key, $value) use (&$counts) {
            if (!isset($counts[$key])) {
                $counts[$key] = $value;
                return true;
            }
            return false;
        });
        $cache->method('set')->willReturnCallback(function (string $key, $value) use (&$counts) {
            $counts[$key] = $value;
            return true;
        });

        $service = new FlagRateLimitService($cache);

        // User1 makes 120 requests — should not throw.
        for ($i = 0; $i < 120; $i++) {
            $service->checkManagement('user1');
        }

        // User2 still at 0 — no throw.
        $this->expectNotToPerformAssertions();
        $service->checkManagement('user2');
    }

    public function test_null_cache_disables_rate_limiting(): void
    {
        $service = new FlagRateLimitService(null);

        $this->expectNotToPerformAssertions();

        for ($i = 0; $i < 10000; $i++) {
            $service->checkManagement('user1');
        }
    }

    public function test_too_many_requests_exception_has_retry_after(): void
    {
        $cache = $this->createMock(AtomicCacheInterface::class);
        $cache->method('get')->willReturn(1001);
        $cache->method('set')->willReturn(true);

        $service = new FlagRateLimitService($cache);

        try {
            $service->checkEval('key1');
            $this->fail('Expected TooManyRequestsException');
        } catch (TooManyRequestsException $e) {
            $this->assertSame(429, $e->getStatusCode());
            $this->assertArrayHasKey('Retry-After', $e->getHeaders());
        }
    }

    public function test_eval_limit_is_higher_than_management(): void
    {
        // Eval allows 1000/60s, management allows 120/60s.
        // At count=121, management throws but eval does not.
        $cache = $this->createMock(AtomicCacheInterface::class);
        $cache->method('get')->willReturn(121);
        $cache->method('set')->willReturn(true);

        $service = new FlagRateLimitService($cache);

        $threw = false;
        try {
            $service->checkManagement('u');
        } catch (TooManyRequestsException) {
            $threw = true;
        }
        $this->assertTrue($threw, 'Management should throw at 121');

        // Eval at 121 should not throw (limit is 1000).
        $threw2 = false;
        try {
            $service->checkEval('k');
        } catch (TooManyRequestsException) {
            $threw2 = true;
        }
        $this->assertFalse($threw2, 'Eval should not throw at 121 (limit is 1000)');
    }
}
