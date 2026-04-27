<?php
declare(strict_types=1);

namespace Vortos\Tests\Authorization\Temporal;

use PHPUnit\Framework\TestCase;
use Vortos\Authorization\Temporal\Storage\RedisTemporalPermissionStore;

final class RedisTemporalPermissionStoreTest extends TestCase
{
    private \Redis $redis;
    private RedisTemporalPermissionStore $store;

    protected function setUp(): void
    {
        $this->redis = $this->createMock(\Redis::class);
        $this->store = new RedisTemporalPermissionStore($this->redis);
    }

    public function test_grant_sets_key_with_ttl(): void
    {
        $expiry = new \DateTimeImmutable('+1 hour');
        $this->redis->expects($this->once())->method('setEx');
        $this->store->grant('user-1', 'beta.feature', $expiry);
    }

    public function test_grant_already_expired_does_not_set_key(): void
    {
        $expiry = new \DateTimeImmutable('-1 hour');
        $this->redis->expects($this->never())->method('setEx');
        $this->store->grant('user-1', 'beta.feature', $expiry);
    }

    public function test_revoke_deletes_key(): void
    {
        $this->redis->expects($this->once())->method('del');
        $this->store->revoke('user-1', 'beta.feature');
    }

    public function test_is_valid_returns_true_when_key_exists(): void
    {
        $this->redis->method('exists')->willReturn(1);
        $this->assertTrue($this->store->isValid('user-1', 'beta.feature'));
    }

    public function test_is_valid_returns_false_when_key_missing(): void
    {
        $this->redis->method('exists')->willReturn(0);
        $this->assertFalse($this->store->isValid('user-1', 'beta.feature'));
    }

    public function test_get_expiry_returns_datetime(): void
    {
        $timestamp = time() + 3600;
        $this->redis->method('get')->willReturn(json_encode(['expires_at' => $timestamp]));
        $expiry = $this->store->getExpiry('user-1', 'beta.feature');
        $this->assertNotNull($expiry);
        $this->assertSame($timestamp, $expiry->getTimestamp());
    }

    public function test_get_expiry_returns_null_when_not_found(): void
    {
        $this->redis->method('get')->willReturn(false);
        $this->assertNull($this->store->getExpiry('user-1', 'beta.feature'));
    }
}
