<?php

declare(strict_types=1);

namespace Vortos\Audit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Vortos\Audit\Enum\AuditExportStatus;
use Vortos\Audit\Enum\Scope;
use Vortos\Audit\Export\AuditExportFilter;
use Vortos\Audit\Export\AuditExportGarbageCollector;
use Vortos\Audit\Export\AuditExportJob;
use Vortos\Audit\Export\AuditExportResult;
use Vortos\Audit\Export\InMemoryExportJobStore;
use Vortos\Audit\Export\InMemoryExportSink;

final class AuditExportGarbageCollectorTest extends TestCase
{
    public function test_collects_only_expired_and_deletes_objects(): void
    {
        $t0    = new \DateTimeImmutable('2026-07-17T10:00:00+00:00');
        $store = new InMemoryExportJobStore();
        $sink  = new InMemoryExportSink();

        // A ready job that expired yesterday.
        $expired = $this->ready($store, $sink, 'old', $t0, $t0->modify('-1 day'));
        // A ready job that expires tomorrow.
        $this->ready($store, $sink, 'fresh', $t0, $t0->modify('+1 day'));

        $gc = new AuditExportGarbageCollector($store, $sink, $this->clock($t0->format('c')));
        $collected = $gc->collect();

        self::assertSame(1, $collected);
        self::assertSame(AuditExportStatus::Expired, $store->find('old')?->status());
        self::assertSame(AuditExportStatus::Ready, $store->find('fresh')?->status());
        // Expired job's objects are gone; fresh job's remain.
        self::assertArrayNotHasKey($expired->bodyKey(), $sink->objects);
        self::assertArrayHasKey($store->find('fresh')?->bodyKey(), $sink->objects);
    }

    private function ready(InMemoryExportJobStore $store, InMemoryExportSink $sink, string $id, \DateTimeImmutable $now, \DateTimeImmutable $expires): AuditExportJob
    {
        $job = AuditExportJob::queue($id, Scope::Tenant, 'org-1', 'u', null, new AuditExportFilter(), $now);
        $job->markRunning($now);
        $bodyKey     = "audit-exports/tenant/org-1/{$id}.ndjson";
        $manifestKey = "audit-exports/tenant/org-1/{$id}.manifest.json";
        $sink->put($bodyKey, 'body', 'application/x-ndjson', 'x');
        $sink->put($manifestKey, '{}', 'application/json', 'x');
        $result = new AuditExportResult($bodyKey, $manifestKey, 1, 4, 'h', [], 'u', $now);
        $job->markReady($result, $now, $expires);
        $store->save($job);

        return $job;
    }

    private function clock(string $iso): ClockInterface
    {
        return new class($iso) implements ClockInterface {
            public function __construct(private readonly string $iso) {}
            public function now(): \DateTimeImmutable { return new \DateTimeImmutable($this->iso); }
        };
    }
}
