<?php
declare(strict_types=1);

namespace Vortos\Tests\Auth\Quota;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Quota\QuotaPeriod;
use Vortos\Auth\Quota\Storage\RedisQuotaStore;

final class RedisQuotaStoreTest extends TestCase
{
    private \Redis $redis;
    private RedisQuotaStore $store;

    protected function setUp(): void
    {
        if (!class_exists(\Redis::class)) {
            $this->markTestSkipped('ext-redis is not installed.');
        }

        $this->redis = $this->createMock(\Redis::class);
        $this->redis->method('time')->willReturn([1717200000, 0]);
        $this->store = new RedisQuotaStore($this->redis);
    }

    public function test_increment_returns_count(): void
    {
        $this->redis->method('incrBy')->willReturn(3);
        $result = $this->store->increment('user-1', 'exports', QuotaPeriod::Monthly);
        $this->assertSame(3, $result);
    }

    public function test_increment_sets_ttl_on_first_increment(): void
    {
        $this->redis->method('incrBy')->willReturn(1);
        $this->redis->expects($this->once())->method('expire');
        $this->store->increment('user-1', 'exports', QuotaPeriod::Monthly);
    }

    public function test_increment_does_not_set_ttl_for_total_period(): void
    {
        $this->redis->method('incrBy')->willReturn(1);
        $this->redis->expects($this->never())->method('expire');
        $this->store->increment('user-1', 'exports', QuotaPeriod::Total);
    }

    public function test_get_returns_current_count(): void
    {
        $this->redis->method('get')->willReturn('47');
        $result = $this->store->get('user-1', 'exports', QuotaPeriod::Monthly);
        $this->assertSame(47, $result);
    }

    public function test_get_returns_zero_when_not_set(): void
    {
        $this->redis->method('get')->willReturn(false);
        $result = $this->store->get('user-1', 'exports', QuotaPeriod::Monthly);
        $this->assertSame(0, $result);
    }

    public function test_reset_deletes_key(): void
    {
        $this->redis->expects($this->once())->method('del');
        $this->store->reset('user-1', 'exports', QuotaPeriod::Monthly);
    }

    public function test_increment_with_custom_cost(): void
    {
        $this->redis->expects($this->once())->method('incrBy')->with($this->anything(), 5)->willReturn(5);
        $this->store->increment('user-1', 'exports', QuotaPeriod::Monthly, 5);
    }

    public function test_consume_returns_allowed_result(): void
    {
        $resetAt = QuotaPeriod::Monthly->getResetAtTimestampAt(1717200000);

        $this->redis->expects($this->once())
            ->method('eval')
            ->with(
                $this->isType('string'),
                $this->callback(function (array $args) use ($resetAt): bool {
                    return $args[0] === 'quota:organization:org-1:checkout.orders:2024-06'
                        && $args[1] === 2
                        && $args[2] === 100
                        && $args[3] === $resetAt - 1717200000
                        && $args[4] === $resetAt;
                }),
                1,
            )
            ->willReturn([1, 42, 58, $resetAt]);

        $result = $this->store->consume(
            bucket: 'organization',
            subjectId: 'org-1',
            quota: 'checkout.orders',
            period: QuotaPeriod::Monthly,
            limit: 100,
            cost: 2,
        );

        $this->assertTrue($result->allowed);
        $this->assertSame(42, $result->current);
        $this->assertSame(58, $result->remaining);
        $this->assertSame($resetAt, $result->resetAt);
    }

    public function test_consume_returns_blocked_result(): void
    {
        $resetAt = QuotaPeriod::Daily->getResetAtTimestampAt(1717200000);

        $this->redis->method('eval')->willReturn([0, 100, 0, $resetAt]);

        $result = $this->store->consume(
            bucket: 'user',
            subjectId: 'user-1',
            quota: 'exports',
            period: QuotaPeriod::Daily,
            limit: 100,
        );

        $this->assertFalse($result->allowed);
        $this->assertSame(100, $result->current);
        $this->assertSame(0, $result->remaining);
        $this->assertSame($resetAt, $result->resetAt);
    }
}
