<?php

declare(strict_types=1);

namespace Vortos\Auth\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Storage\InMemoryTokenStorage;

final class InMemoryTokenStorageTest extends TestCase
{
    private InMemoryTokenStorage $storage;

    protected function setUp(): void
    {
        $this->storage = new InMemoryTokenStorage();
    }

    protected function tearDown(): void
    {
        $this->storage->clear();
    }

    public function test_store_and_consume_returns_user_id(): void
    {
        $this->storage->store('jti-1', 'user-1', time() + 3600);

        $this->assertSame('user-1', $this->storage->consume('jti-1'));
    }

    public function test_consume_returns_null_for_unknown_jti(): void
    {
        $this->assertNull($this->storage->consume('nonexistent-jti'));
    }

    public function test_consume_returns_null_for_expired_token(): void
    {
        $this->storage->store('jti-expired', 'user-1', time() - 1);

        $this->assertNull($this->storage->consume('jti-expired'));
    }

    public function test_consume_is_exactly_once(): void
    {
        $this->storage->store('jti-1', 'user-1', time() + 3600);

        $this->assertSame('user-1', $this->storage->consume('jti-1'));
        $this->assertNull($this->storage->consume('jti-1'));
    }

    public function test_revoke_makes_consume_return_null(): void
    {
        $this->storage->store('jti-1', 'user-1', time() + 3600);

        $this->storage->revoke('jti-1');

        $this->assertNull($this->storage->consume('jti-1'));
    }

    public function test_revoke_all_for_user_invalidates_all_their_tokens(): void
    {
        $this->storage->store('jti-a', 'user-1', time() + 3600);
        $this->storage->store('jti-b', 'user-1', time() + 3600);
        $this->storage->store('jti-c', 'user-2', time() + 3600);

        $this->storage->revokeAllForUser('user-1');

        $this->assertNull($this->storage->consume('jti-a'));
        $this->assertNull($this->storage->consume('jti-b'));
        $this->assertSame('user-2', $this->storage->consume('jti-c'));
    }

    public function test_revoke_nonexistent_jti_does_not_throw(): void
    {
        $this->expectNotToPerformAssertions();
        $this->storage->revoke('nonexistent-jti');
    }
}
