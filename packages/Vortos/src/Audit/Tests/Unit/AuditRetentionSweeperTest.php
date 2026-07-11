<?php

declare(strict_types=1);

namespace Vortos\Audit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Vortos\Audit\Enum\Scope;
use Vortos\Audit\Event\AuditActor;
use Vortos\Audit\Event\AuditEvent;
use Vortos\Audit\Integrity\AuditChainVerifier;
use Vortos\Audit\Integrity\AuditHashChain;
use Vortos\Audit\Retention\AuditCheckpoint;
use Vortos\Audit\Retention\AuditCheckpointStoreInterface;
use Vortos\Audit\Retention\AuditRetentionPolicy;
use Vortos\Audit\Retention\AuditRetentionSourceInterface;
use Vortos\Audit\Retention\AuditRetentionSweeper;
use Vortos\Audit\Retention\InMemoryArchiveWriter;
use Vortos\Audit\Retention\StoredAuditEventSerializer;
use Vortos\Audit\Storage\StoredAuditEvent;

final class AuditRetentionSweeperTest extends TestCase
{
    public const NOW = '2026-07-11T12:00:00.000000+00:00';

    /** Build a valid 5-record chain: seq 1-3 are 100d old, seq 4-5 are 1d old. */
    private function buildChain(): array
    {
        $chain = new AuditHashChain();
        $now   = new \DateTimeImmutable(self::NOW);
        $ages  = [100, 100, 100, 1, 1];
        $out   = [];
        $prev  = AuditHashChain::GENESIS_HASH;

        foreach ($ages as $i => $daysAgo) {
            $seq   = $i + 1;
            $event = AuditEvent::create(
                Scope::Tenant, 'org-1', AuditActor::system(), 'member.invited',
                occurredAt: $now->modify("-{$daysAgo} days"),
            );
            $stored = $chain->chain($event, 'tenant:org-1', $seq, $prev, 'k');
            $out[]  = $stored;
            $prev   = $stored->contentHash;
        }

        return $out;
    }

    public function test_archives_expired_prefix_and_leaves_remaining_verifiable(): void
    {
        $source     = new FakeRetentionSource(['tenant:org-1' => $this->buildChain()]);
        $checkpoints = new FakeCheckpointStore();
        $writer     = new InMemoryArchiveWriter();
        $policy     = new AuditRetentionPolicy(platformDays: 730, tenantDefaultDays: 30);

        $sweeper = new AuditRetentionSweeper(
            $source, $checkpoints, $writer, $policy,
            new StoredAuditEventSerializer(), $this->fixedClock(),
        );

        $result = $sweeper->sweep();

        // seq 1-3 (100d old) archived+purged; seq 4-5 (1d) retained.
        self::assertSame(3, $result->total());
        self::assertCount(1, $writer->segments);
        self::assertCount(2, $source->records['tenant:org-1']);

        $cp = $checkpoints->find('tenant:org-1');
        self::assertNotNull($cp);
        self::assertSame(3, $cp->lastSequence);

        // The critical property: the remaining hot chain still verifies FROM the checkpoint.
        $remaining = array_values($source->records['tenant:org-1']);
        $verify = (new AuditChainVerifier(new AuditHashChain()))->verify(
            $remaining, 'k', expectedFirstSequence: $cp->lastSequence + 1, expectedPrevHash: $cp->lastContentHash,
        );
        self::assertTrue($verify->valid, (string) $verify->reason);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $source     = new FakeRetentionSource(['tenant:org-1' => $this->buildChain()]);
        $checkpoints = new FakeCheckpointStore();
        $writer     = new InMemoryArchiveWriter();

        $sweeper = new AuditRetentionSweeper(
            $source, $checkpoints, $writer,
            new AuditRetentionPolicy(730, 30), new StoredAuditEventSerializer(), $this->fixedClock(),
        );

        $result = $sweeper->sweep(dryRun: true);

        self::assertSame(3, $result->total());          // reports what it would do
        self::assertCount(0, $writer->segments);        // but wrote nothing
        self::assertCount(5, $source->records['tenant:org-1']); // deleted nothing
        self::assertNull($checkpoints->find('tenant:org-1'));
    }

    public function test_disabled_retention_is_skipped(): void
    {
        $source     = new FakeRetentionSource(['tenant:org-1' => $this->buildChain()]);
        $sweeper = new AuditRetentionSweeper(
            $source, new FakeCheckpointStore(), new InMemoryArchiveWriter(),
            new AuditRetentionPolicy(730, 0), // 0 = never purge tenants
            new StoredAuditEventSerializer(), $this->fixedClock(),
        );

        self::assertSame(0, $sweeper->sweep()->total());
        self::assertCount(5, $source->records['tenant:org-1']);
    }

    private function fixedClock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): \DateTimeImmutable { return new \DateTimeImmutable(AuditRetentionSweeperTest::NOW); }
        };
    }
}

/** In-memory store implementing the sweeper's source port. */
final class FakeRetentionSource implements AuditRetentionSourceInterface
{
    /** @var array<string, list<StoredAuditEvent>> */
    public array $records;

    /** @param array<string, list<StoredAuditEvent>> $records */
    public function __construct(array $records)
    {
        $this->records = $records;
    }

    public function chainsWithRecordsBefore(\DateTimeImmutable $cutoff): array
    {
        $out = [];
        foreach ($this->records as $chainKey => $recs) {
            foreach ($recs as $r) {
                if ($r->event->occurredAt < $cutoff) { $out[] = $chainKey; break; }
            }
        }
        return $out;
    }

    public function readChain(string $chainKey, int $afterSequence, int $limit): array
    {
        $recs = array_values(array_filter(
            $this->records[$chainKey] ?? [],
            static fn (StoredAuditEvent $r): bool => $r->sequence > $afterSequence,
        ));
        usort($recs, static fn ($a, $b) => $a->sequence <=> $b->sequence);
        return array_slice($recs, 0, $limit);
    }

    public function deleteChainUpTo(string $chainKey, int $sequence): int
    {
        $before = count($this->records[$chainKey] ?? []);
        $this->records[$chainKey] = array_values(array_filter(
            $this->records[$chainKey] ?? [],
            static fn (StoredAuditEvent $r): bool => $r->sequence > $sequence,
        ));
        return $before - count($this->records[$chainKey]);
    }
}

final class FakeCheckpointStore implements AuditCheckpointStoreInterface
{
    /** @var array<string, AuditCheckpoint> */
    private array $latest = [];

    public function find(string $chainKey): ?AuditCheckpoint
    {
        return $this->latest[$chainKey] ?? null;
    }

    public function save(AuditCheckpoint $checkpoint): void
    {
        $this->latest[$checkpoint->chainKey] = $checkpoint;
    }
}
