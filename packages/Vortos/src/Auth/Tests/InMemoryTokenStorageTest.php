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

    public function test_store_and_is_valid(): void
    {
        $this->storage->store('jti-1', 'user-1', time() + 3600);

        $this->assertTrue($this->storage->isValid('jti-1'));
    }

    public function test_is_valid_returns_false_for_unknown_jti(): void
    {
        $this->assertFalse($this->storage->isValid('nonexistent-jti'));
    }

    public function test_is_valid_returns_false_for_expired_token(): void
    {
        $this->storage->store('jti-expired', 'user-1', time() - 1); // already expired

        $this->assertFalse($this->storage->isValid('jti-expired'));
    }

    public function test_revoke_invalidates_token(): void
    {
        $this->storage->store('jti-1', 'user-1', time() + 3600);
        $this->assertTrue($this->storage->isValid('jti-1'));

        $this->storage->revoke('jti-1');

        $this->assertFalse($this->storage->isValid('jti-1'));
    }

    public function test_revoke_all_for_user_invalidates_all_their_tokens(): void
    {
        $this->storage->store('jti-a', 'user-1', time() + 3600);
        $this->storage->store('jti-b', 'user-1', time() + 3600);
        $this->storage->store('jti-c', 'user-2', time() + 3600); // different user

        $this->storage->revokeAllForUser('user-1');

        $this->assertFalse($this->storage->isValid('jti-a'));
        $this->assertFalse($this->storage->isValid('jti-b'));
        $this->assertTrue($this->storage->isValid('jti-c')); // unaffected
    }

    public function test_revoke_nonexistent_jti_does_not_throw(): void
    {
        $this->expectNotToPerformAssertions();
        $this->storage->revoke('nonexistent-jti'); // should not throw
    }
}
