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

        $result = $this->storage->consume('jti-1');
        $this->assertTrue($result->isRotated());
        $this->assertSame('user-1', $result->userId);
    }

    public function test_consume_reports_reused_for_unknown_jti(): void
    {
        $this->assertTrue($this->storage->consume('nonexistent-jti')->isReused());
    }

    public function test_consume_reports_reused_for_expired_token(): void
    {
        $this->storage->store('jti-expired', 'user-1', time() - 1);

        $this->assertTrue($this->storage->consume('jti-expired')->isReused());
    }

    public function test_consume_is_exactly_once(): void
    {
        $this->storage->store('jti-1', 'user-1', time() + 3600);

        $this->assertTrue($this->storage->consume('jti-1')->isRotated());
        // Second consume of a never-revoked, already-consumed token is reuse (theft).
        $this->assertTrue($this->storage->consume('jti-1')->isReused());
    }

    public function test_revoke_makes_consume_report_revoked_not_reused(): void
    {
        $this->storage->store('jti-1', 'user-1', time() + 3600);

        $this->storage->revoke('jti-1');

        // Deliberate revoke → Revoked, NOT Reused. This is what stops a single-device
        // sign-out from being misread as theft and nuking every session.
        $this->assertTrue($this->storage->consume('jti-1')->isRevoked());
    }

    public function test_revoke_all_for_user_invalidates_all_their_tokens(): void
    {
        $this->storage->store('jti-a', 'user-1', time() + 3600);
        $this->storage->store('jti-b', 'user-1', time() + 3600);
        $this->storage->store('jti-c', 'user-2', time() + 3600);

        $this->storage->revokeAllForUser('user-1');

        // Deliberate mass-revoke leaves tombstones → other devices see a clean Revoked.
        $this->assertTrue($this->storage->consume('jti-a')->isRevoked());
        $this->assertTrue($this->storage->consume('jti-b')->isRevoked());

        $survivor = $this->storage->consume('jti-c');
        $this->assertTrue($survivor->isRotated());
        $this->assertSame('user-2', $survivor->userId);
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

        $this->assertTrue($this->storage->consume('jti-1')->isRotated());
        $this->assertTrue($this->storage->consume('jti-1')->isReused());
    }

    public function test_grace_window_allows_immediate_re_consume(): void
    {
        $storage = new InMemoryTokenStorage(rotationGraceSeconds: 30);
        $storage->store('jti-1', 'user-1', time() + 3600);

        // First consume rotates the token; a racing re-presentation within the window is benign.
        $this->assertTrue($storage->consume('jti-1')->isRotated());
        $second = $storage->consume('jti-1');
        $this->assertTrue($second->isRotated());
        $this->assertSame('user-1', $second->userId);
    }

    public function test_grace_expiry_rejects_reuse_after_window(): void
    {
        $storage = new InMemoryTokenStorage(rotationGraceSeconds: 30);
        $storage->store('jti-1', 'user-1', time() + 3600);
        $this->assertTrue($storage->consume('jti-1')->isRotated());

        // Force the marker to appear expired.
        $ref = new \ReflectionProperty($storage, 'grace');
        $grace = $ref->getValue($storage);
        $grace['jti-1']['expiresAt'] = time() - 1;
        $ref->setValue($storage, $grace);

        $this->assertTrue($storage->consume('jti-1')->isReused());
    }

    public function test_grace_does_not_apply_to_never_issued_jti(): void
    {
        $storage = new InMemoryTokenStorage(rotationGraceSeconds: 30);

        // A jti that was never stored is not benign — it must not slip through on grace.
        $this->assertTrue($storage->consume('never-issued')->isReused());
    }

    public function test_revoke_wins_over_grace_marker(): void
    {
        $storage = new InMemoryTokenStorage(rotationGraceSeconds: 30);
        $storage->store('jti-1', 'user-1', time() + 3600);
        $this->assertTrue($storage->consume('jti-1')->isRotated());

        // Explicit revocation must not leave a usable grace marker behind, and must
        // classify a subsequent presentation as a deliberate Revoked.
        $storage->revoke('jti-1');
        $this->assertTrue($storage->consume('jti-1')->isRevoked());
    }

    public function test_revoke_all_for_user_clears_grace_markers(): void
    {
        $storage = new InMemoryTokenStorage(rotationGraceSeconds: 30);
        $storage->store('jti-a', 'user-1', time() + 3600);
        $this->assertTrue($storage->consume('jti-a')->isRotated());

        // jti-a was already rotated away (only a grace marker remained, not an active token).
        // A mass-revoke clears that grace marker, so re-presenting the ancient consumed token
        // is reuse — it must not slip through on grace after a logout-all.
        $storage->revokeAllForUser('user-1');
        $this->assertTrue($storage->consume('jti-a')->isReused());
    }

    public function test_expired_revocation_tombstone_falls_through_to_reused(): void
    {
        $storage = new InMemoryTokenStorage();
        $storage->store('jti-1', 'user-1', time() + 3600);
        $storage->revoke('jti-1');
        $this->assertTrue($storage->consume('jti-1')->isRevoked());

        // Force the tombstone to appear expired — a much later presentation is no longer
        // classifiable as a deliberate revoke and degrades to Reused.
        $ref = new \ReflectionProperty($storage, 'revoked');
        $revoked = $ref->getValue($storage);
        $revoked['jti-1'] = time() - 1;
        $ref->setValue($storage, $revoked);

        $this->assertTrue($storage->consume('jti-1')->isReused());
    }
}
