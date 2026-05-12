<?php
declare(strict_types=1);

namespace Vortos\Tests\Auth\Session;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Session\Storage\RedisSessionStore;

final class RedisSessionStoreTest extends TestCase
{
    private \Redis $redis;
    private RedisSessionStore $store;

    protected function setUp(): void
    {
        if (!class_exists(\Redis::class)) {
            $this->markTestSkipped('ext-redis is not installed.');
        }

        $this->redis = $this->createMock(\Redis::class);
        $this->store = new RedisSessionStore($this->redis);
    }

    public function test_add_session_adds_to_sorted_set(): void
    {
        $this->redis->expects($this->once())->method('zAdd');
        $this->redis->expects($this->once())->method('expire');
        $this->store->addSession('user-1', 'jti-abc', time(), 3600);
    }

    public function test_remove_session_removes_from_sorted_set(): void
    {
        $this->redis->expects($this->once())->method('zRem')
            ->with('vortos_auth:sessions:user-1', 'jti-abc');
        $this->store->removeSession('user-1', 'jti-abc');
    }

    public function test_get_session_count(): void
    {
        $this->redis->method('zCard')->willReturn(3);
        $this->assertSame(3, $this->store->getSessionCount('user-1'));
    }

    public function test_get_oldest_session(): void
    {
        $this->redis->method('zRange')->willReturn(['jti-oldest']);
        $this->assertSame('jti-oldest', $this->store->getOldestSession('user-1'));
    }

    public function test_get_oldest_session_returns_null_when_empty(): void
    {
        $this->redis->method('zRange')->willReturn([]);
        $this->assertNull($this->store->getOldestSession('user-1'));
    }

    public function test_remove_oldest_session(): void
    {
        $this->redis->method('zRange')->willReturn(['jti-oldest']);
        $this->redis->expects($this->once())->method('zRem');
        $oldest = $this->store->removeOldestSession('user-1');
        $this->assertSame('jti-oldest', $oldest);
    }

    public function test_clear_all_removes_key(): void
    {
        $this->redis->expects($this->once())->method('del')->with('vortos_auth:sessions:user-1');
        $this->store->clearAll('user-1');
    }
}
