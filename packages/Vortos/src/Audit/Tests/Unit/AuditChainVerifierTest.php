<?php

declare(strict_types=1);

namespace Vortos\Audit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vortos\Audit\Enum\Scope;
use Vortos\Audit\Event\AuditActor;
use Vortos\Audit\Event\AuditEvent;
use Vortos\Audit\Integrity\AuditChainVerifier;
use Vortos\Audit\Integrity\AuditHashChain;
use Vortos\Audit\Storage\StoredAuditEvent;

final class AuditChainVerifierTest extends TestCase
{
    private const KEY = 'unit-test-hmac-key';

    /**
     * Build a valid chain of $n records the way the store would.
     *
     * @return list<StoredAuditEvent>
     */
    private function buildChain(int $n, string $key = self::KEY): array
    {
        $chain = new AuditHashChain();
        $out   = [];
        $prev  = AuditHashChain::GENESIS_HASH;

        for ($i = 1; $i <= $n; $i++) {
            $event    = AuditEvent::create(Scope::Tenant, 'org-1', AuditActor::system(), 'member.invited', context: ['n' => $i]);
            $stored   = $chain->chain($event, 'tenant:org-1', $i, $prev, $key);
            $out[]    = $stored;
            $prev     = $stored->contentHash;
        }

        return $out;
    }

    public function test_valid_chain_verifies(): void
    {
        $verifier = new AuditChainVerifier(new AuditHashChain());
        $result   = $verifier->verify($this->buildChain(5), self::KEY);

        self::assertTrue($result->valid);
        self::assertSame(5, $result->verifiedCount);
    }

    public function test_detects_tampered_content(): void
    {
        $entries = $this->buildChain(5);

        // Forge the event body of record #3 while keeping its stored hashes.
        $forged = new AuditEvent(
            id: $entries[2]->event->id,
            scope: Scope::Tenant,
            tenantId: 'org-1',
            actor: AuditActor::system(),
            action: 'member.removed', // changed
            target: null,
            sensitivity: $entries[2]->event->sensitivity,
            outcome: $entries[2]->event->outcome,
            source: $entries[2]->event->source,
            context: $entries[2]->event->context,
            occurredAt: $entries[2]->event->occurredAt,
        );
        $entries[2] = new StoredAuditEvent(
            $forged,
            $entries[2]->chainKey,
            $entries[2]->sequence,
            $entries[2]->prevHash,
            $entries[2]->contentHash, // stale hash, no longer matches forged content
            $entries[2]->signature,
        );

        $result = (new AuditChainVerifier(new AuditHashChain()))->verify($entries, self::KEY);

        self::assertFalse($result->valid);
        self::assertSame(3, $result->brokenSequence);
        self::assertStringContainsString('Content tampered', (string) $result->reason);
    }

    public function test_detects_removed_record_as_sequence_gap(): void
    {
        $entries = $this->buildChain(5);
        unset($entries[2]);                 // drop sequence 3
        $entries = array_values($entries);

        $result = (new AuditChainVerifier(new AuditHashChain()))->verify($entries, self::KEY);

        self::assertFalse($result->valid);
        self::assertSame(4, $result->brokenSequence);
        self::assertStringContainsString('Non-contiguous', (string) $result->reason);
    }

    public function test_detects_wrong_signing_key(): void
    {
        $entries = $this->buildChain(3, 'the-real-key');

        $result = (new AuditChainVerifier(new AuditHashChain()))->verify($entries, 'attacker-key');

        self::assertFalse($result->valid);
        self::assertStringContainsString('Invalid signature', (string) $result->reason);
    }
}
