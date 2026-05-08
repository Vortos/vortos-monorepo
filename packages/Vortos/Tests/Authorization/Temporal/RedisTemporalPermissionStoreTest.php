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
        $this->redis->method('ttl')->willReturn(-2); // index key does not exist
        $this->redis->method('multi')->willReturnSelf();
        $this->redis->method('exec')->willReturn([true, true, true]);
        $this->redis->expects($this->once())->method('setEx');
        $this->store->grant('user-1', 'beta.feature', $expiry);
    }

    public function test_grant_already_expired_does_not_set_key(): void
    {
        $expiry = new \DateTimeImmutable('-1 hour');
        $this->redis->expects($this->never())->method('setEx');
        $this->redis->expects($this->never())->method('multi');
        $this->store->grant('user-1', 'beta.feature', $expiry);
    }

    public function test_grant_does_not_shrink_existing_index_ttl(): void
    {
        // Index has 86400s remaining; new grant is only 300s — expire must NOT be called
        $expiry = new \DateTimeImmutable('+5 minutes');
        $this->redis->method('ttl')->willReturn(86400);
        $this->redis->method('multi')->willReturnSelf();
        $this->redis->method('exec')->willReturn([true, true]);
        // Only setEx + sAdd inside the pipeline, no expire call
        $this->redis->expects($this->once())->method('setEx');
        $this->redis->expects($this->once())->method('sAdd');
        $this->redis->expects($this->never())->method('expire');
        $this->store->grant('user-1', 'short.grant', $expiry);
    }

    public function test_grant_extends_index_ttl_when_new_grant_is_longer(): void
    {
        // Index has 60s remaining; new grant is 3600s — expire should be called
        $expiry = new \DateTimeImmutable('+1 hour');
        $this->redis->method('ttl')->willReturn(60);
        $this->redis->method('multi')->willReturnSelf();
        $this->redis->method('exec')->willReturn([true, true, true]);
        $this->redis->expects($this->once())->method('expire');
        $this->store->grant('user-1', 'beta.feature', $expiry);
    }

    public function test_revoke_deletes_key(): void
    {
        $this->redis->method('multi')->willReturnSelf();
        $this->redis->method('exec')->willReturn([true, true]);
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

    public function test_active_grants_returns_only_valid_permissions(): void
    {
        $this->redis->method('sMembers')->willReturn(['perm.a', 'perm.b']);
        // Pipeline: perm.a exists, perm.b does not
        $this->redis->method('multi')->willReturnSelf();
        $this->redis->method('exec')->willReturn([1, 0]);
        $this->redis->expects($this->once())->method('sRem')
            ->with('temporal_perms:user-1', 'perm.b');

        $active = $this->store->activeGrantsForUser('user-1');
        $this->assertSame(['perm.a'], $active);
    }
}
