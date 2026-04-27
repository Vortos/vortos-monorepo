<?php
declare(strict_types=1);

namespace Vortos\Tests\Authorization\Scope;

use PHPUnit\Framework\TestCase;
use Vortos\Authorization\Scope\Storage\RedisScopedPermissionStore;

final class RedisScopedPermissionStoreTest extends TestCase
{
    private \Redis $redis;
    private RedisScopedPermissionStore $store;

    protected function setUp(): void
    {
        $this->redis = $this->createMock(\Redis::class);
        $this->store = new RedisScopedPermissionStore($this->redis);
    }

    public function test_grant_sets_key(): void
    {
        $this->redis->expects($this->once())->method('set')
            ->with('scoped_perm:org:org-123:user-456:documents.edit', '1');
        $this->store->grant('user-456', 'org', 'org-123', 'documents.edit');
    }

    public function test_grant_with_expiry_uses_setex(): void
    {
        $expiry = new \DateTimeImmutable('+1 hour');
        $this->redis->expects($this->once())->method('setEx');
        $this->store->grant('user-456', 'org', 'org-123', 'documents.edit', $expiry);
    }

    public function test_revoke_deletes_key(): void
    {
        $this->redis->expects($this->once())->method('del');
        $this->store->revoke('user-456', 'org', 'org-123', 'documents.edit');
    }

    public function test_has_returns_true_when_key_exists(): void
    {
        $this->redis->method('exists')->willReturn(1);
        $this->assertTrue($this->store->has('user-456', 'org', 'org-123', 'documents.edit'));
    }

    public function test_has_returns_false_when_key_missing(): void
    {
        $this->redis->method('exists')->willReturn(0);
        $this->assertFalse($this->store->has('user-456', 'org', 'org-123', 'documents.edit'));
    }

    public function test_revoke_all_uses_keys_pattern(): void
    {
        $this->redis->method('keys')->willReturn(['key1', 'key2']);
        $this->redis->expects($this->once())->method('del');
        $this->store->revokeAll('user-456', 'org', 'org-123');
    }

    public function test_revoke_all_does_nothing_when_no_keys(): void
    {
        $this->redis->method('keys')->willReturn([]);
        $this->redis->expects($this->never())->method('del');
        $this->store->revokeAll('user-456', 'org', 'org-123');
    }
}
