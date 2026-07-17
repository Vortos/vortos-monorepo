<?php

declare(strict_types=1);

namespace Vortos\Audit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Vortos\Audit\Enum\Scope;
use Vortos\Audit\Event\AuditActor;
use Vortos\Audit\Event\AuditEvent;
use Vortos\Audit\Export\InMemoryExportSink;
use Vortos\Audit\Export\StreamingAuditExporter;
use Vortos\Audit\Integrity\AuditHashChain;
use Vortos\Audit\Query\AuditCursor;
use Vortos\Audit\Query\AuditFacets;
use Vortos\Audit\Query\AuditPage;
use Vortos\Audit\Query\AuditQuery;
use Vortos\Audit\Query\AuditQueryInterface;
use Vortos\Audit\Retention\StoredAuditEventSerializer;
use Vortos\Audit\Storage\StoredAuditEvent;

final class StreamingAuditExporterTest extends TestCase
{
    private const KEY = 'export-key';

    public function test_streams_all_pages_to_sink_and_signs_manifest(): void
    {
        $chain = new AuditHashChain();
        $query = new FakePagedQuery($this->records($chain, 7), pageSize: 3); // 3 pages: 3,3,1
        $sink  = new InMemoryExportSink();
        $clock = $this->fixedClock('2026-07-17T10:00:00.000000+00:00');

        $exporter = new StreamingAuditExporter(
            $query, new StoredAuditEventSerializer(), $chain, $sink, $clock,
            hmacKey: self::KEY, keyPrefix: 'audit-exports', pageSize: 3, downloadTtlSeconds: 3600,
        );

        $result = $exporter->export(new AuditQuery(Scope::Tenant, 'org-1'), 'exp-1');

        // All 7 records exported across the pages.
        self::assertSame(7, $result->recordCount);
        self::assertSame(7, $result->manifest['record_count']);

        // Body + manifest landed in the sink under scope-partitioned keys.
        self::assertSame('audit-exports/tenant/org-1/exp-1.ndjson', $result->bodyKey);
        self::assertArrayHasKey('audit-exports/tenant/org-1/exp-1.ndjson', $sink->objects);
        self::assertArrayHasKey('audit-exports/tenant/org-1/exp-1.manifest.json', $sink->objects);

        $ndjson = $sink->objects[$result->bodyKey];
        self::assertCount(7, explode("\n", $ndjson));

        // Content hash covers the exact streamed body, and the manifest is bound to it.
        $expectedHash = hash('sha256', $ndjson);
        self::assertSame($expectedHash, $result->contentSha256);
        self::assertSame($expectedHash, $result->manifest['content_sha256']);
        self::assertSame(\strlen($ndjson), $result->byteSize);

        // Signature verifies against the same signing message the retired exporter used (parity).
        $msg = implode('|', ['tenant', 'org-1', '', '', '7', $expectedHash, $result->manifest['generated_at']]);
        self::assertTrue($chain->verifySignature($msg, $result->manifest['signature'], self::KEY));
        self::assertFalse($chain->verifySignature($msg, $result->manifest['signature'], 'wrong-key'));

        // Download URL points at the body; expiry = generated_at + TTL.
        self::assertSame('memory://' . $result->bodyKey, $result->downloadUrl);
        self::assertSame('2026-07-17T11:00:00', $result->expiresAt->format('Y-m-d\TH:i:s'));
    }

    public function test_empty_export_produces_zero_records_and_empty_body(): void
    {
        $chain = new AuditHashChain();
        $sink  = new InMemoryExportSink();

        $exporter = new StreamingAuditExporter(
            new FakePagedQuery([], pageSize: 3), new StoredAuditEventSerializer(), $chain, $sink,
            $this->fixedClock('2026-07-17T10:00:00.000000+00:00'), hmacKey: self::KEY,
        );

        $result = $exporter->export(new AuditQuery(Scope::Tenant, 'org-1'), 'exp-empty');

        self::assertSame(0, $result->recordCount);
        self::assertSame(0, $result->byteSize);
        self::assertSame('', $sink->objects[$result->bodyKey]);
        self::assertSame(hash('sha256', ''), $result->contentSha256);
    }

    /** @return list<StoredAuditEvent> */
    private function records(AuditHashChain $chain, int $n): array
    {
        $out = [];
        $prev = AuditHashChain::GENESIS_HASH;
        for ($i = 1; $i <= $n; $i++) {
            $event  = AuditEvent::create(Scope::Tenant, 'org-1', AuditActor::system(), 'member.invited', context: ['n' => $i]);
            $stored = $chain->chain($event, 'tenant:org-1', $i, $prev, self::KEY);
            $out[]  = $stored;
            $prev   = $stored->contentHash;
        }
        return $out;
    }

    private function fixedClock(string $iso): ClockInterface
    {
        return new class($iso) implements ClockInterface {
            public function __construct(private readonly string $iso) {}
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable($this->iso);
            }
        };
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
                if ($r->event->id === $query->cursor->id) {
                    $offset = $idx + 1;
                    break;
                }
            }
        }
        $slice   = array_slice($this->records, $offset, $this->pageSize);
        $hasMore = ($offset + $this->pageSize) < count($this->records);
        $next    = null;
        if ($hasMore && $slice !== []) {
            $last = $slice[array_key_last($slice)];
            $next = new AuditCursor($last->event->occurredAt->format('Y-m-d\TH:i:s.uP'), $last->event->id);
        }
        return new AuditPage(array_values($slice), $next);
    }

    public function facets(AuditQuery $query): AuditFacets
    {
        return new AuditFacets([], [], []);
    }
}
