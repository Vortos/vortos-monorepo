<?php

declare(strict_types=1);

namespace Vortos\Audit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vortos\Audit\Enum\Scope;
use Vortos\Audit\Event\AuditActor;
use Vortos\Audit\Event\AuditEvent;
use Vortos\Audit\Export\AuditExporter;
use Vortos\Audit\Integrity\AuditHashChain;
use Vortos\Audit\Query\AuditCursor;
use Vortos\Audit\Query\AuditPage;
use Vortos\Audit\Query\AuditQuery;
use Vortos\Audit\Query\AuditQueryInterface;
use Vortos\Audit\Retention\StoredAuditEventSerializer;
use Vortos\Audit\Storage\StoredAuditEvent;

final class AuditExporterTest extends TestCase
{
    private const KEY = 'export-key';

    public function test_cursor_round_trips(): void
    {
        $c = new AuditCursor('2026-07-11T12:00:00.000000+00:00', 'id-9');
        $decoded = AuditCursor::decode($c->encode());

        self::assertSame($c->occurredAt, $decoded?->occurredAt);
        self::assertSame('id-9', $decoded?->id);
    }

    public function test_pages_to_exhaustion_and_signs_manifest(): void
    {
        $chain = new AuditHashChain();
        $query = new FakePagedQuery($this->records($chain, 7), pageSize: 3); // 3 pages: 3,3,1

        $exporter = new AuditExporter($query, new StoredAuditEventSerializer(), $chain, self::KEY, pageSize: 3);
        $export   = $exporter->export(new AuditQuery(Scope::Tenant, 'org-1'));

        // All 7 records exported across the pages.
        self::assertSame(7, $export->manifest['record_count']);
        self::assertCount(7, explode("\n", $export->ndjson));

        // Manifest signature verifies and is bound to the content hash.
        $expectedHash = hash('sha256', $export->ndjson);
        self::assertSame($expectedHash, $export->manifest['content_sha256']);

        $msg = implode('|', [
            'tenant', 'org-1', '', '', '7', $expectedHash, $export->manifest['generated_at'],
        ]);
        self::assertTrue($chain->verifySignature($msg, $export->manifest['signature'], self::KEY));
        self::assertFalse($chain->verifySignature($msg, $export->manifest['signature'], 'wrong-key'));
    }

    /** @return list<StoredAuditEvent> */
    private function records(AuditHashChain $chain, int $n): array
    {
        $out = [];
        $prev = AuditHashChain::GENESIS_HASH;
        for ($i = 1; $i <= $n; $i++) {
            $event = AuditEvent::create(Scope::Tenant, 'org-1', AuditActor::system(), 'member.invited', context: ['n' => $i]);
            $stored = $chain->chain($event, 'tenant:org-1', $i, $prev, self::KEY);
            $out[] = $stored;
            $prev = $stored->contentHash;
        }
        return $out;
    }
}

/** Returns the record list in fixed-size pages, honouring the cursor. */
final class FakePagedQuery implements AuditQueryInterface
{
    /** @param list<StoredAuditEvent> $records */
    public function __construct(private readonly array $records, private readonly int $pageSize) {}

    public function page(AuditQuery $query): AuditPage
    {
        $offset = 0;
        if ($query->cursor !== null) {
            foreach ($this->records as $idx => $r) {
                if ($r->event->id === $query->cursor->id) { $offset = $idx + 1; break; }
            }
        }
        $slice = array_slice($this->records, $offset, $this->pageSize);
        $hasMore = ($offset + $this->pageSize) < count($this->records);
        $next = null;
        if ($hasMore && $slice !== []) {
            $last = $slice[array_key_last($slice)];
            $next = new AuditCursor($last->event->occurredAt->format('Y-m-d\TH:i:s.uP'), $last->event->id);
        }
        return new AuditPage(array_values($slice), $next);
    }
}
