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

    // ── Rotation grace window ────────────────────────────────────────────────

    public function test_grace_disabled_by_default_rejects_reuse(): void
    {
        // Default construction => strict one-time-use; a second consume is reuse.
        $this->storage->store('jti-1', 'user-1', time() + 3600);

        $this->assertSame('user-1', $this->storage->consume('jti-1'));
        $this->assertNull($this->storage->consume('jti-1'));
    }

    public function test_grace_window_allows_immediate_re_consume(): void
    {
        $storage = new InMemoryTokenStorage(rotationGraceSeconds: 30);
        $storage->store('jti-1', 'user-1', time() + 3600);

        // First consume rotates the token; a racing re-presentation within the window is benign.
        $this->assertSame('user-1', $storage->consume('jti-1'));
        $this->assertSame('user-1', $storage->consume('jti-1'));
    }

    public function test_grace_expiry_rejects_reuse_after_window(): void
    {
        // Zero-length window: the grace marker is already expired the instant it is written,
        // so a re-consume is treated as genuine reuse — proving the window is time-bounded.
        $storage = new InMemoryTokenStorage(rotationGraceSeconds: 30);
        $storage->store('jti-1', 'user-1', time() + 3600);
        $this->assertSame('user-1', $storage->consume('jti-1'));

        // Force the marker to appear expired.
        $ref = new \ReflectionProperty($storage, 'grace');
        $grace = $ref->getValue($storage);
        $grace['jti-1']['expiresAt'] = time() - 1;
        $ref->setValue($storage, $grace);

        $this->assertNull($storage->consume('jti-1'));
    }

    public function test_grace_does_not_apply_to_never_issued_jti(): void
    {
        $storage = new InMemoryTokenStorage(rotationGraceSeconds: 30);

        // A jti that was never stored is not benign — it must not slip through on grace.
        $this->assertNull($storage->consume('never-issued'));
    }

    public function test_revoke_clears_grace_marker(): void
    {
        $storage = new InMemoryTokenStorage(rotationGraceSeconds: 30);
        $storage->store('jti-1', 'user-1', time() + 3600);
        $this->assertSame('user-1', $storage->consume('jti-1'));

        // Explicit revocation must not leave a usable grace marker behind.
        $storage->revoke('jti-1');
        $this->assertNull($storage->consume('jti-1'));
    }

    public function test_revoke_all_for_user_clears_grace_markers(): void
    {
        $storage = new InMemoryTokenStorage(rotationGraceSeconds: 30);
        $storage->store('jti-a', 'user-1', time() + 3600);
        $this->assertSame('user-1', $storage->consume('jti-a'));

        // Theft response revokes everything — grace markers included.
        $storage->revokeAllForUser('user-1');
        $this->assertNull($storage->consume('jti-a'));
    }
}
