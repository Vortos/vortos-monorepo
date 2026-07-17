<?php

declare(strict_types=1);

namespace Vortos\Audit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Vortos\Audit\Enum\AuditExportStatus;
use Vortos\Audit\Enum\Scope;
use Vortos\Audit\Event\AuditActor;
use Vortos\Audit\Event\AuditEvent;
use Vortos\Audit\Export\AuditExportFilter;
use Vortos\Audit\Export\AuditExportJob;
use Vortos\Audit\Export\AuditExportNotifierInterface;
use Vortos\Audit\Export\AuditExportRequestHandler;
use Vortos\Audit\Export\AuditExportRequested;
use Vortos\Audit\Export\AuditExportResult;
use Vortos\Audit\Export\InMemoryExportJobStore;
use Vortos\Audit\Export\InMemoryExportSink;
use Vortos\Audit\Export\StreamingAuditExporter;
use Vortos\Audit\Integrity\AuditHashChain;
use Vortos\Audit\Query\AuditPage;
use Vortos\Audit\Query\AuditQuery;
use Vortos\Audit\Query\AuditQueryInterface;
use Vortos\Audit\Retention\StoredAuditEventSerializer;
use Vortos\Audit\Storage\StoredAuditEvent;

final class AuditExportRequestHandlerTest extends TestCase
{
    private const KEY = 'k';

    public function test_runs_queued_job_to_ready_and_notifies(): void
    {
        $chain = new AuditHashChain();
        $store = new InMemoryExportJobStore();
        $sink  = new InMemoryExportSink();
        $clock = $this->clock('2026-07-17T10:00:00+00:00');

        $job = AuditExportJob::queue('exp-1', Scope::Tenant, 'org-1', 'u1', 'Ada', new AuditExportFilter(), $clock->now());
        $store->save($job);

        $exporter = new StreamingAuditExporter(
            new FakePagedQuery($this->records($chain, 5), pageSize: 2),
            new StoredAuditEventSerializer(), $chain, $sink, $clock, hmacKey: self::KEY, pageSize: 2,
        );
        $notifier = new SpyNotifier();

        (new AuditExportRequestHandler($store, $exporter, $clock, artifactRetentionSeconds: 3600, notifier: $notifier))
            ->__invoke(new AuditExportRequested('exp-1'));

        $saved = $store->find('exp-1');
        self::assertSame(AuditExportStatus::Ready, $saved?->status());
        self::assertSame(5, $saved?->recordCount());
        self::assertNotNull($saved?->bodyKey());
        self::assertArrayHasKey($saved->bodyKey(), $sink->objects);
        self::assertSame(1, $notifier->readyCount);
        self::assertSame(0, $notifier->failedCount);
        // Artifact expiry = now + retention.
        self::assertSame('2026-07-17T11:00:00', $saved->expiresAt()?->format('Y-m-d\TH:i:s'));
    }

    public function test_failure_marks_failed_and_notifies_failed(): void
    {
        $store = new InMemoryExportJobStore();
        $clock = $this->clock('2026-07-17T10:00:00+00:00');
        $job   = AuditExportJob::queue('exp-2', Scope::Platform, null, 'u', null, new AuditExportFilter(), $clock->now());
        $store->save($job);

        $throwingQuery = new class implements AuditQueryInterface {
            public function page(AuditQuery $query): AuditPage { throw new \RuntimeException('reader down'); }
            public function facets(AuditQuery $query): \Vortos\Audit\Query\AuditFacets { return new \Vortos\Audit\Query\AuditFacets([], [], []); }
        };
        $exporter = new StreamingAuditExporter($throwingQuery, new StoredAuditEventSerializer(), new AuditHashChain(), new InMemoryExportSink(), $clock);
        $notifier = new SpyNotifier();

        (new AuditExportRequestHandler($store, $exporter, $clock, notifier: $notifier))
            ->__invoke(new AuditExportRequested('exp-2'));

        $saved = $store->find('exp-2');
        self::assertSame(AuditExportStatus::Failed, $saved?->status());
        self::assertStringContainsString('reader down', (string) $saved?->error());
        self::assertSame(1, $notifier->failedCount);
    }

    public function test_terminal_job_is_a_noop(): void
    {
        $store = new InMemoryExportJobStore();
        $clock = $this->clock('2026-07-17T10:00:00+00:00');
        $job   = AuditExportJob::queue('exp-3', Scope::Platform, null, 'u', null, new AuditExportFilter(), $clock->now());
        $job->markRunning($clock->now());
        $result = new AuditExportResult('b', 'm', 1, 1, 'h', [], 'u', $clock->now());
        $job->markReady($result, $clock->now(), $clock->now()->modify('+1 day'));
        $store->save($job);

        // A query that would throw if actually invoked proves the handler short-circuits.
        $exporter = new StreamingAuditExporter(
            new class implements AuditQueryInterface {
                public function page(AuditQuery $query): AuditPage { throw new \RuntimeException('should not run'); }
                public function facets(AuditQuery $query): \Vortos\Audit\Query\AuditFacets { return new \Vortos\Audit\Query\AuditFacets([], [], []); }
            },
            new StoredAuditEventSerializer(), new AuditHashChain(), new InMemoryExportSink(), $clock,
        );

        (new AuditExportRequestHandler($store, $exporter, $clock))->__invoke(new AuditExportRequested('exp-3'));

        self::assertSame(AuditExportStatus::Ready, $store->find('exp-3')?->status());
    }

    /** @return list<StoredAuditEvent> */
    private function records(AuditHashChain $chain, int $n): array
    {
        $out = []; $prev = AuditHashChain::GENESIS_HASH;
        for ($i = 1; $i <= $n; $i++) {
            $e = AuditEvent::create(Scope::Tenant, 'org-1', AuditActor::system(), 'member.invited', context: ['n' => $i]);
            $s = $chain->chain($e, 'tenant:org-1', $i, $prev, self::KEY);
            $out[] = $s; $prev = $s->contentHash;
        }
        return $out;
    }

    private function clock(string $iso): ClockInterface
    {
        return new class($iso) implements ClockInterface {
            public function __construct(private readonly string $iso) {}
            public function now(): \DateTimeImmutable { return new \DateTimeImmutable($this->iso); }
        };
    }
}

final class SpyNotifier implements AuditExportNotifierInterface
{
    public int $readyCount = 0;
    public int $failedCount = 0;

    public function exportReady(AuditExportJob $job, AuditExportResult $result): void { $this->readyCount++; }
    public function exportFailed(AuditExportJob $job, string $error): void { $this->failedCount++; }
}
