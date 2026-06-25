<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Unit\RateLimit;

use PHPUnit\Framework\TestCase;
use Vortos\Alerts\RateLimit\OutboundRateLimitConfig;
use Vortos\Alerts\RateLimit\RateLimitDecision;
use Vortos\Alerts\RateLimit\SlidingWindowOutboundRateLimiter;

final class SlidingWindowOutboundRateLimiterTest extends TestCase
{
    public function test_allows_under_tenant_cap(): void
    {
        $limiter = new SlidingWindowOutboundRateLimiter(new OutboundRateLimitConfig(perTenantPerHour: 5, globalPerHour: 0));

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(RateLimitDecision::Allowed, $limiter->tryConsume('t1', 'slack'));
        }
    }

    public function test_tenant_cap_exhausted(): void
    {
        $limiter = new SlidingWindowOutboundRateLimiter(new OutboundRateLimitConfig(perTenantPerHour: 3, globalPerHour: 0));

        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(RateLimitDecision::Allowed, $limiter->tryConsume('t1', 'slack'));
        }

        $this->assertSame(RateLimitDecision::TenantExhausted, $limiter->tryConsume('t1', 'slack'));
    }

    public function test_tenant_caps_are_independent(): void
    {
        $limiter = new SlidingWindowOutboundRateLimiter(new OutboundRateLimitConfig(perTenantPerHour: 2, globalPerHour: 0));

        $this->assertSame(RateLimitDecision::Allowed, $limiter->tryConsume('t1', 'slack'));
        $this->assertSame(RateLimitDecision::Allowed, $limiter->tryConsume('t1', 'slack'));
        $this->assertSame(RateLimitDecision::TenantExhausted, $limiter->tryConsume('t1', 'slack'));

        $this->assertSame(RateLimitDecision::Allowed, $limiter->tryConsume('t2', 'slack'));
    }

    public function test_global_cap_exhausted(): void
    {
        $limiter = new SlidingWindowOutboundRateLimiter(new OutboundRateLimitConfig(perTenantPerHour: 0, globalPerHour: 3));

        $this->assertSame(RateLimitDecision::Allowed, $limiter->tryConsume('t1', 'slack'));
        $this->assertSame(RateLimitDecision::Allowed, $limiter->tryConsume('t2', 'slack'));
        $this->assertSame(RateLimitDecision::Allowed, $limiter->tryConsume('t3', 'slack'));

        $this->assertSame(RateLimitDecision::GlobalExhausted, $limiter->tryConsume('t4', 'slack'));
    }

    public function test_global_cap_checked_before_tenant_cap(): void
    {
        $limiter = new SlidingWindowOutboundRateLimiter(new OutboundRateLimitConfig(perTenantPerHour: 10, globalPerHour: 2));

        $this->assertSame(RateLimitDecision::Allowed, $limiter->tryConsume('t1', 'slack'));
        $this->assertSame(RateLimitDecision::Allowed, $limiter->tryConsume('t2', 'slack'));
        $this->assertSame(RateLimitDecision::GlobalExhausted, $limiter->tryConsume('t3', 'slack'));
    }

    public function test_unlimited_when_both_caps_zero(): void
    {
        $limiter = new SlidingWindowOutboundRateLimiter(new OutboundRateLimitConfig(perTenantPerHour: 0, globalPerHour: 0));

        for ($i = 0; $i < 1000; $i++) {
            $this->assertSame(RateLimitDecision::Allowed, $limiter->tryConsume('t1', 'slack'));
        }
    }

    public function test_per_channel_kind_cap(): void
    {
        $limiter = new SlidingWindowOutboundRateLimiter(new OutboundRateLimitConfig(
            perTenantPerHour: 0,
            globalPerHour: 0,
            perChannelKindPerHour: ['ses' => 2],
        ));

        $this->assertSame(RateLimitDecision::Allowed, $limiter->tryConsume('t1', 'ses'));
        $this->assertSame(RateLimitDecision::Allowed, $limiter->tryConsume('t1', 'ses'));
        $this->assertSame(RateLimitDecision::TenantExhausted, $limiter->tryConsume('t1', 'ses'));

        $this->assertSame(RateLimitDecision::Allowed, $limiter->tryConsume('t1', 'slack'));
    }

    public function test_per_channel_kind_caps_are_per_tenant(): void
    {
        $limiter = new SlidingWindowOutboundRateLimiter(new OutboundRateLimitConfig(
            perTenantPerHour: 0,
            globalPerHour: 0,
            perChannelKindPerHour: ['ses' => 1],
        ));

        $this->assertSame(RateLimitDecision::Allowed, $limiter->tryConsume('t1', 'ses'));
        $this->assertSame(RateLimitDecision::TenantExhausted, $limiter->tryConsume('t1', 'ses'));

        $this->assertSame(RateLimitDecision::Allowed, $limiter->tryConsume('t2', 'ses'));
    }

    public function test_many_distinct_fingerprints_hit_tenant_cap(): void
    {
        $limiter = new SlidingWindowOutboundRateLimiter(new OutboundRateLimitConfig(perTenantPerHour: 10, globalPerHour: 0));

        $allowed = 0;
        $limited = 0;

        for ($i = 0; $i < 50; $i++) {
            $result = $limiter->tryConsume('tenant-a', 'webhook');
            if ($result === RateLimitDecision::Allowed) {
                $allowed++;
            } else {
                $limited++;
            }
        }

        $this->assertSame(10, $allowed);
        $this->assertSame(40, $limited);
    }
}
