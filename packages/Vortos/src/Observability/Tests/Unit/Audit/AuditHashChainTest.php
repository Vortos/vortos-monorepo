<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Unit\Audit;

use PHPUnit\Framework\TestCase;
use Vortos\Observability\Audit\AuditHashChain;

final class AuditHashChainTest extends TestCase
{
    private AuditHashChain $chain;

    protected function setUp(): void
    {
        $this->chain = new AuditHashChain();
    }

    public function test_genesis_hash_is_sha256_of_empty_string(): void
    {
        self::assertSame(hash('sha256', ''), AuditHashChain::GENESIS_HASH);
    }

    public function test_content_hash_is_deterministic_regardless_of_key_order(): void
    {
        $a = ['b' => 1, 'a' => 2, 'nested' => ['z' => 1, 'y' => 2]];
        $b = ['a' => 2, 'b' => 1, 'nested' => ['y' => 2, 'z' => 1]];

        self::assertSame(
            $this->chain->contentHash($a, AuditHashChain::GENESIS_HASH),
            $this->chain->contentHash($b, AuditHashChain::GENESIS_HASH),
        );
    }

    public function test_content_hash_changes_when_content_changes(): void
    {
        $h1 = $this->chain->contentHash(['x' => 1], AuditHashChain::GENESIS_HASH);
        $h2 = $this->chain->contentHash(['x' => 2], AuditHashChain::GENESIS_HASH);

        self::assertNotSame($h1, $h2);
    }

    public function test_content_hash_changes_when_prev_hash_changes(): void
    {
        $h1 = $this->chain->contentHash(['x' => 1], AuditHashChain::GENESIS_HASH);
        $h2 = $this->chain->contentHash(['x' => 1], 'some-other-prev-hash');

        self::assertNotSame($h1, $h2);
    }

    public function test_sign_and_verify_round_trip(): void
    {
        $message = $this->chain->signingMessage('entry-1', 0, 'content-hash', AuditHashChain::GENESIS_HASH);
        $signature = $this->chain->sign($message, 'secret-key');

        self::assertTrue($this->chain->verifySignature($message, $signature, 'secret-key'));
        self::assertFalse($this->chain->verifySignature($message, $signature, 'wrong-key'));
    }

    public function test_sign_throws_on_empty_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->chain->sign('msg', '');
    }

    public function test_verify_signature_returns_false_on_empty_key_without_throwing(): void
    {
        self::assertFalse($this->chain->verifySignature('msg', 'sig', ''));
    }

    public function test_chain_links_entries_to_predecessor_content_hash(): void
    {
        $first = $this->chain->chain(
            'e1', 0, 'DeployAttempted', 'actor', 'oidc', 'prod',
            'build-1', 'sha1', 'sha256:' . str_repeat('a', 64), 'fp-1',
            null, '2026-01-01T00:00:00+00:00', [], AuditHashChain::GENESIS_HASH, 'key',
        );

        $second = $this->chain->chain(
            'e2', 1, 'DeploySucceeded', 'actor', 'oidc', 'prod',
            'build-1', 'sha1', 'sha256:' . str_repeat('a', 64), 'fp-1',
            null, '2026-01-01T00:01:00+00:00', [], $first->contentHash, 'key',
        );

        self::assertSame($first->contentHash, $second->prevHash);
        self::assertNotSame($first->contentHash, $second->contentHash);

        $message1 = $this->chain->signingMessage($first->entryId, $first->sequence, $first->contentHash, $first->prevHash);
        self::assertTrue($this->chain->verifySignature($message1, $first->signature, 'key'));
    }
}
