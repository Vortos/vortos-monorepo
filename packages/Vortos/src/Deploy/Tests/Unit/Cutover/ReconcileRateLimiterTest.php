<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Cutover;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Cutover\ReconcileRateLimiter;
use Vortos\Deploy\Tests\Fixtures\InMemoryRateLimitStateStore;

final class ReconcileRateLimiterTest extends TestCase
{
    public function test_allows_first_call_as_boot_bypass(): void
    {
        $limiter = new ReconcileRateLimiter(new InMemoryRateLimitStateStore(), minIntervalSeconds: 60);
        $this->assertTrue($limiter->allow('production'));
    }

    public function test_denies_second_call_within_cooldown(): void
    {
        $limiter = new ReconcileRateLimiter(new InMemoryRateLimitStateStore(), minIntervalSeconds: 60);

        $limiter->record('production');
        $this->assertFalse($limiter->allow('production'));
    }

    public function test_allows_different_env(): void
    {
        $limiter = new ReconcileRateLimiter(new InMemoryRateLimitStateStore(), minIntervalSeconds: 60);

        $limiter->record('production');
        $this->assertTrue($limiter->allow('staging'));
    }

    public function test_rejects_invalid_interval(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ReconcileRateLimiter(new InMemoryRateLimitStateStore(), minIntervalSeconds: 0);
    }

    public function test_boot_bypass_used_tracked(): void
    {
        $limiter = new ReconcileRateLimiter(new InMemoryRateLimitStateStore(), minIntervalSeconds: 10);
        $this->assertFalse($limiter->bootBypassUsed());

        $limiter->record('production');
        $this->assertTrue($limiter->bootBypassUsed());
    }

    public function test_state_persists_across_instances(): void
    {
        $store = new InMemoryRateLimitStateStore();

        $limiter1 = new ReconcileRateLimiter($store, minIntervalSeconds: 60);
        $limiter1->record('production');

        $limiter2 = new ReconcileRateLimiter($store, minIntervalSeconds: 60);
        $limiter2->record('staging');
        $this->assertFalse($limiter2->allow('production'));
    }
}
