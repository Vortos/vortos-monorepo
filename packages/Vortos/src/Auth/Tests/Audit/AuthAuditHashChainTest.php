<?php
declare(strict_types=1);

namespace Vortos\Auth\Tests\Audit;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Audit\AuditEntry;
use Vortos\Auth\Audit\Integrity\AuthAuditHashChain;

final class AuthAuditHashChainTest extends TestCase
{
    private AuthAuditHashChain $chain;

    protected function setUp(): void
    {
        $this->chain = new AuthAuditHashChain();
    }

    public function test_genesis_hash_is_sha256_of_empty_string(): void
    {
        self::assertSame(hash('sha256', ''), AuthAuditHashChain::GENESIS_HASH);
    }

    public function test_content_hash_is_deterministic(): void
    {
        $fields = ['user_id' => 'u1', 'action' => 'login'];
        $h1 = $this->chain->contentHash($fields, AuthAuditHashChain::GENESIS_HASH);
        $h2 = $this->chain->contentHash($fields, AuthAuditHashChain::GENESIS_HASH);
        self::assertSame($h1, $h2);
    }

    public function test_content_hash_differs_with_different_data(): void
    {
        $h1 = $this->chain->contentHash(['x' => 1], AuthAuditHashChain::GENESIS_HASH);
        $h2 = $this->chain->contentHash(['x' => 2], AuthAuditHashChain::GENESIS_HASH);
        self::assertNotSame($h1, $h2);
    }

    public function test_content_hash_differs_with_different_prev_hash(): void
    {
        $fields = ['x' => 1];
        $h1 = $this->chain->contentHash($fields, AuthAuditHashChain::GENESIS_HASH);
        $h2 = $this->chain->contentHash($fields, hash('sha256', 'different'));
        self::assertNotSame($h1, $h2);
    }

    public function test_content_hash_is_key_order_independent(): void
    {
        $h1 = $this->chain->contentHash(['a' => 1, 'b' => 2], AuthAuditHashChain::GENESIS_HASH);
        $h2 = $this->chain->contentHash(['b' => 2, 'a' => 1], AuthAuditHashChain::GENESIS_HASH);
        self::assertSame($h1, $h2);
    }

    public function test_sign_throws_on_empty_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->chain->sign('message', '');
    }

    public function test_sign_returns_hmac_sha256(): void
    {
        $sig = $this->chain->sign('message', 'key');
        self::assertSame(hash_hmac('sha256', 'message', 'key'), $sig);
    }

    public function test_verify_signature_accepts_valid(): void
    {
        $sig = $this->chain->sign('msg', 'key');
        self::assertTrue($this->chain->verifySignature('msg', $sig, 'key'));
    }

    public function test_verify_signature_rejects_wrong_key(): void
    {
        $sig = $this->chain->sign('msg', 'key1');
        self::assertFalse($this->chain->verifySignature('msg', $sig, 'key2'));
    }

    public function test_verify_signature_rejects_empty_key(): void
    {
        self::assertFalse($this->chain->verifySignature('msg', 'sig', ''));
    }

    public function test_chain_produces_fully_chained_entry(): void
    {
        $entry = AuditEntry::create('user-1', 'login', 'res-1', '10.0.0.1', 'Agent');
        $hmacKey = bin2hex(random_bytes(32));

        $chained = $this->chain->chain($entry, 0, AuthAuditHashChain::GENESIS_HASH, $hmacKey);

        self::assertTrue($chained->isChained());
        self::assertSame(0, $chained->sequence);
        self::assertSame(AuthAuditHashChain::GENESIS_HASH, $chained->prevHash);
        self::assertSame(64, strlen($chained->contentHash));
        self::assertSame(64, strlen($chained->signature));
        self::assertSame($entry->id, $chained->id);
        self::assertSame($entry->userId, $chained->userId);
    }

    public function test_chain_builds_valid_link(): void
    {
        $hmacKey = bin2hex(random_bytes(32));
        $e1 = AuditEntry::create('u1', 'login');
        $c1 = $this->chain->chain($e1, 0, AuthAuditHashChain::GENESIS_HASH, $hmacKey);

        $e2 = AuditEntry::create('u2', 'logout');
        $c2 = $this->chain->chain($e2, 1, $c1->contentHash, $hmacKey);

        self::assertSame($c1->contentHash, $c2->prevHash);
        self::assertSame(1, $c2->sequence);
    }
}
