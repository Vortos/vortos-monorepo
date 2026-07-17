<?php

declare(strict_types=1);

namespace Vortos\Audit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vortos\Audit\Admin\AuditAdminService;
use Vortos\Audit\Enum\Scope;
use Vortos\Audit\Event\AuditActor;
use Vortos\Audit\Event\AuditEvent;
use Vortos\Audit\Integrity\AuditChainVerifier;
use Vortos\Audit\Integrity\AuditHashChain;
use Vortos\Audit\Query\AuditQueryInterface;
use Vortos\Audit\Storage\AuditReaderInterface;
use Vortos\Audit\Storage\StoredAuditEvent;

final class AuditAdminServiceTest extends TestCase
{
    private const KEY = 'admin-key';

    private function service(array $records): AuditAdminService
    {
        $reader = new class($records) implements AuditReaderInterface {
            /** @param list<StoredAuditEvent> $records */
            public function __construct(private array $records) {}
            public function chainTail(string $chainKey): ?array { return null; }
            public function readChain(string $chainKey, int $afterSequence, int $limit): array
            {
                $out = array_values(array_filter($this->records, fn ($r) => $r->sequence > $afterSequence));
                usort($out, fn ($a, $b) => $a->sequence <=> $b->sequence);
                return array_slice($out, 0, $limit);
            }
        };
        $query = $this->createStub(AuditQueryInterface::class);
        $chain = new AuditHashChain();

        return new AuditAdminService(
            $query, $reader, new AuditChainVerifier($chain), self::KEY,
            checkpoints: null, verifyBatchSize: 2, // small batch to exercise streaming
        );
    }

    /** @return list<StoredAuditEvent> */
    private function chain(int $n, bool $tamper = false): array
    {
        $chain = new AuditHashChain();
        $out = []; $prev = AuditHashChain::GENESIS_HASH;
        for ($i = 1; $i <= $n; $i++) {
            $event = AuditEvent::create(Scope::Platform, null, AuditActor::system(), 'flag.published', context: ['n' => $i]);
            $stored = $chain->chain($event, 'platform', $i, $prev, self::KEY);
            $out[] = $stored;
            $prev = $stored->contentHash;
        }
        if ($tamper) {
            $bad = $out[2];
            $out[2] = new StoredAuditEvent($bad->event, $bad->chainKey, $bad->sequence, $bad->prevHash, str_repeat('f', 64), $bad->signature);
        }
        return $out;
    }

    public function test_verifies_a_valid_multi_batch_chain(): void
    {
        $result = $this->service($this->chain(5))->verifyChain('platform');

        self::assertTrue($result->valid, (string) $result->reason);
        self::assertSame(5, $result->verifiedCount);
    }

    public function test_detects_tamper_across_batches(): void
    {
        $result = $this->service($this->chain(5, tamper: true))->verifyChain('platform');

        self::assertFalse($result->valid);
        self::assertSame(3, $result->brokenSequence);
    }
}
