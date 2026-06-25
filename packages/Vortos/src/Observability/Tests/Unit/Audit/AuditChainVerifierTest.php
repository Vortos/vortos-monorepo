<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Unit\Audit;

use PHPUnit\Framework\TestCase;
use Vortos\Observability\Audit\AuditEntry;
use Vortos\Observability\Audit\AuditChainVerifier;
use Vortos\Observability\Audit\AuditHashChain;

final class AuditChainVerifierTest extends TestCase
{
    private const KEY = 'test-hmac-key';

    private AuditHashChain $chain;
    private AuditChainVerifier $verifier;

    protected function setUp(): void
    {
        $this->chain = new AuditHashChain();
        $this->verifier = new AuditChainVerifier($this->chain);
    }

    /** @return list<AuditEntry> */
    private function buildChain(int $count): array
    {
        $entries = [];
        $prevHash = AuditHashChain::GENESIS_HASH;

        for ($i = 0; $i < $count; $i++) {
            $entry = $this->chain->chain(
                "entry-{$i}", $i, 'DeployAttempted', 'actor', 'oidc', 'prod',
                "build-{$i}", 'sha1', 'sha256:' . str_repeat('a', 64), 'fp-1',
                null, "2026-01-01T00:0{$i}:00+00:00", ['n' => $i], $prevHash, self::KEY,
            );
            $entries[] = $entry;
            $prevHash = $entry->contentHash;
        }

        return $entries;
    }

    public function test_intact_chain_verifies(): void
    {
        $result = $this->verifier->verify($this->buildChain(5), self::KEY);

        self::assertTrue($result->intact);
        self::assertNull($result->brokenSequence);
    }

    public function test_mutated_middle_entry_reports_exact_broken_sequence(): void
    {
        $entries = $this->buildChain(5);

        $mutated = $entries[2];
        $entries[2] = new AuditEntry(
            entryId: $mutated->entryId,
            sequence: $mutated->sequence,
            eventType: $mutated->eventType,
            actorId: $mutated->actorId,
            actorIdentitySource: $mutated->actorIdentitySource,
            env: $mutated->env,
            buildId: $mutated->buildId,
            gitSha: $mutated->gitSha,
            imageDigest: $mutated->imageDigest,
            schemaFingerprintId: $mutated->schemaFingerprintId,
            reason: 'tampered',
            occurredAt: $mutated->occurredAt,
            data: $mutated->data,
            prevHash: $mutated->prevHash,
            contentHash: $mutated->contentHash,
            signature: $mutated->signature,
        );

        $result = $this->verifier->verify($entries, self::KEY);

        self::assertFalse($result->intact);
        self::assertSame(2, $result->brokenSequence);
    }

    public function test_truncated_tail_breaks_the_link_at_the_gap(): void
    {
        $entries = $this->buildChain(5);
        unset($entries[2]);
        $remaining = array_values($entries);

        $result = $this->verifier->verify($remaining, self::KEY);

        self::assertFalse($result->intact);
        self::assertSame(3, $result->brokenSequence);
    }

    public function test_reordered_entries_are_detected(): void
    {
        $entries = $this->buildChain(4);
        [$entries[1], $entries[2]] = [$entries[2], $entries[1]];

        $result = $this->verifier->verify($entries, self::KEY);

        self::assertFalse($result->intact);
    }

    public function test_forged_signature_is_detected(): void
    {
        $entries = $this->buildChain(3);

        $result = $this->verifier->verify($entries, 'a-different-key');

        self::assertFalse($result->intact);
        self::assertSame(0, $result->brokenSequence);
        self::assertSame('HMAC signature invalid (forged or signed with a different key)', $result->reason);
    }

    public function test_empty_chain_is_intact(): void
    {
        $result = $this->verifier->verify([], self::KEY);

        self::assertTrue($result->intact);
    }
}
