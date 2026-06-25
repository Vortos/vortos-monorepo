<?php
declare(strict_types=1);

namespace Vortos\Auth\Tests\Audit;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Audit\AuditEntry;
use Vortos\Auth\Audit\Integrity\AuthAuditHashChain;
use Vortos\Auth\Audit\Integrity\InMemoryChainStateStore;

final class InMemoryChainStateStoreTest extends TestCase
{
    public function test_first_append_starts_at_sequence_zero_with_genesis(): void
    {
        $store = new InMemoryChainStateStore();
        $chain = new AuthAuditHashChain();
        $hmacKey = bin2hex(random_bytes(32));

        $entry = $store->appendChained(
            fn (int $seq, string $prev) => $chain->chain(
                AuditEntry::create('u', 'login'),
                $seq,
                $prev,
                $hmacKey,
            ),
        );

        $this->assertSame(0, $entry->sequence);
        $this->assertSame(AuthAuditHashChain::GENESIS_HASH, $entry->prevHash);
    }

    public function test_sequential_appends_advance_state(): void
    {
        $store = new InMemoryChainStateStore();
        $chain = new AuthAuditHashChain();
        $hmacKey = bin2hex(random_bytes(32));

        $e1 = $store->appendChained(fn (int $s, string $p) => $chain->chain(AuditEntry::create('u', 'a'), $s, $p, $hmacKey));
        $e2 = $store->appendChained(fn (int $s, string $p) => $chain->chain(AuditEntry::create('u', 'b'), $s, $p, $hmacKey));
        $e3 = $store->appendChained(fn (int $s, string $p) => $chain->chain(AuditEntry::create('u', 'c'), $s, $p, $hmacKey));

        $this->assertSame(0, $e1->sequence);
        $this->assertSame(1, $e2->sequence);
        $this->assertSame(2, $e3->sequence);
        $this->assertSame($e1->contentHash, $e2->prevHash);
        $this->assertSame($e2->contentHash, $e3->prevHash);

        $this->assertSame(3, $store->getSequence());
        $this->assertSame($e3->contentHash, $store->getPrevHash());
    }
}
