<?php
declare(strict_types=1);

namespace Vortos\Auth\Tests\Audit;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Audit\AuditEntry;
use Vortos\Auth\Audit\Integrity\AuthAuditChainVerifier;
use Vortos\Auth\Audit\Integrity\AuthAuditHashChain;

final class AuthAuditChainVerifierTest extends TestCase
{
    private AuthAuditHashChain $chain;
    private AuthAuditChainVerifier $verifier;
    private string $hmacKey;

    protected function setUp(): void
    {
        $this->chain = new AuthAuditHashChain();
        $this->verifier = new AuthAuditChainVerifier($this->chain);
        $this->hmacKey = bin2hex(random_bytes(32));
    }

    private function buildChain(int $count): array
    {
        $entries = [];
        $prevHash = AuthAuditHashChain::GENESIS_HASH;

        for ($i = 0; $i < $count; $i++) {
            $entry = AuditEntry::create("user-{$i}", "action.{$i}");
            $chained = $this->chain->chain($entry, $i, $prevHash, $this->hmacKey);
            $entries[] = $chained;
            $prevHash = $chained->contentHash;
        }

        return $entries;
    }

    public function test_empty_chain_is_intact(): void
    {
        $result = $this->verifier->verify([], $this->hmacKey);
        $this->assertTrue($result->intact);
    }

    public function test_single_entry_chain_is_intact(): void
    {
        $entries = $this->buildChain(1);
        $result = $this->verifier->verify($entries, $this->hmacKey);
        $this->assertTrue($result->intact);
    }

    public function test_multi_entry_chain_is_intact(): void
    {
        $entries = $this->buildChain(5);
        $result = $this->verifier->verify($entries, $this->hmacKey);
        $this->assertTrue($result->intact);
    }

    public function test_detects_mutated_entry(): void
    {
        $entries = $this->buildChain(3);

        $tampered = new AuditEntry(
            id: $entries[1]->id,
            userId: 'hacker',
            action: $entries[1]->action,
            resourceId: $entries[1]->resourceId,
            ipAddress: $entries[1]->ipAddress,
            userAgent: $entries[1]->userAgent,
            occurredAt: $entries[1]->occurredAt,
            metadata: $entries[1]->metadata,
            sequence: $entries[1]->sequence,
            prevHash: $entries[1]->prevHash,
            contentHash: $entries[1]->contentHash,
            signature: $entries[1]->signature,
        );
        $entries[1] = $tampered;

        $result = $this->verifier->verify($entries, $this->hmacKey);
        $this->assertFalse($result->intact);
        $this->assertSame(1, $result->brokenSequence);
        $this->assertStringContainsString('content hash mismatch', $result->reason);
    }

    public function test_detects_broken_chain_link(): void
    {
        $entries = $this->buildChain(3);

        $detached = $this->chain->chain(
            AuditEntry::create('u', 'a'),
            1,
            'aaaa' . str_repeat('0', 60),
            $this->hmacKey,
        );
        $entries[1] = $detached;

        $result = $this->verifier->verify($entries, $this->hmacKey);
        $this->assertFalse($result->intact);
        $this->assertSame(1, $result->brokenSequence);
        $this->assertStringContainsString('prevHash', $result->reason);
    }

    public function test_detects_sequence_gap(): void
    {
        $entries = $this->buildChain(3);

        $skipped = $this->chain->chain(
            AuditEntry::create('u', 'a'),
            5,
            $entries[1]->contentHash,
            $this->hmacKey,
        );
        $entries[2] = $skipped;

        $result = $this->verifier->verify($entries, $this->hmacKey);
        $this->assertFalse($result->intact);
        $this->assertStringContainsString('sequence gap', $result->reason);
    }

    public function test_detects_forged_signature(): void
    {
        $entries = $this->buildChain(3);

        $forgedKey = bin2hex(random_bytes(32));
        $forged = $this->chain->chain(
            AuditEntry::create($entries[1]->userId, $entries[1]->action),
            1,
            $entries[0]->contentHash,
            $forgedKey,
        );
        $entries[1] = $forged;

        $result = $this->verifier->verify($entries, $this->hmacKey);
        $this->assertFalse($result->intact);
        $this->assertStringContainsString('HMAC signature invalid', $result->reason);
    }

    public function test_detects_wrong_hmac_key(): void
    {
        $entries = $this->buildChain(3);
        $wrongKey = bin2hex(random_bytes(32));

        $result = $this->verifier->verify($entries, $wrongKey);
        $this->assertFalse($result->intact);
        $this->assertSame(0, $result->brokenSequence);
    }

    public function test_detects_unchained_entry(): void
    {
        $entry = AuditEntry::create('u', 'a');
        $result = $this->verifier->verify([$entry], $this->hmacKey);
        $this->assertFalse($result->intact);
        $this->assertStringContainsString('missing integrity fields', $result->reason);
    }

    public function test_detects_truncation(): void
    {
        $entries = $this->buildChain(5);
        array_splice($entries, 2, 1);

        $result = $this->verifier->verify($entries, $this->hmacKey);
        $this->assertFalse($result->intact);
    }

    public function test_verification_result_to_array(): void
    {
        $result = $this->verifier->verify($this->buildChain(3), $this->hmacKey);
        $arr = $result->toArray();

        $this->assertTrue($arr['intact']);
        $this->assertNull($arr['broken_sequence']);
    }
}
