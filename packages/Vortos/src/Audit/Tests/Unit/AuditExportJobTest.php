<?php

declare(strict_types=1);

namespace Vortos\Audit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vortos\Audit\Enum\AuditExportStatus;
use Vortos\Audit\Enum\Scope;
use Vortos\Audit\Export\AuditExportFilter;
use Vortos\Audit\Export\AuditExportJob;
use Vortos\Audit\Export\AuditExportResult;

final class AuditExportJobTest extends TestCase
{
    public function test_happy_path_queue_running_ready(): void
    {
        $now = new \DateTimeImmutable('2026-07-17T10:00:00+00:00');
        $job = AuditExportJob::queue('exp-1', Scope::Tenant, 'org-1', 'user-1', 'Ada', new AuditExportFilter(), $now);

        self::assertSame(AuditExportStatus::Queued, $job->status());

        $job->markRunning($now->modify('+1 second'));
        self::assertSame(AuditExportStatus::Running, $job->status());

        $result = new AuditExportResult('b.ndjson', 'b.manifest.json', 42, 1024, 'hash', ['record_count' => 42], 'https://x', $now);
        $expires = $now->modify('+7 days');
        $job->markReady($result, $now->modify('+2 seconds'), $expires);

        self::assertSame(AuditExportStatus::Ready, $job->status());
        self::assertSame(42, $job->recordCount());
        self::assertSame(1024, $job->byteSize());
        self::assertSame('b.ndjson', $job->bodyKey());
        self::assertSame($expires, $job->expiresAt());
    }

    public function test_markReady_requires_running(): void
    {
        $now = new \DateTimeImmutable();
        $job = AuditExportJob::queue('exp-2', Scope::Platform, null, 'u', null, new AuditExportFilter(), $now);

        $this->expectException(\LogicException::class);
        $result = new AuditExportResult('b', 'm', 0, 0, 'h', [], 'u', $now);
        $job->markReady($result, $now, $now); // still Queued
    }

    public function test_transitions_are_idempotent_on_terminal(): void
    {
        $now = new \DateTimeImmutable();
        $job = AuditExportJob::queue('exp-3', Scope::Platform, null, 'u', null, new AuditExportFilter(), $now);
        $job->markRunning($now);
        $job->markFailed('boom', $now);

        self::assertSame(AuditExportStatus::Failed, $job->status());
        // Failing again is a no-op (terminal), not a throw.
        $job->markFailed('again', $now);
        self::assertSame(AuditExportStatus::Failed, $job->status());

        // But re-starting a terminal job is illegal — the guard must reject it.
        $this->expectException(\LogicException::class);
        $job->markRunning($now);
    }

    public function test_is_past_retention(): void
    {
        $now = new \DateTimeImmutable('2026-07-17T10:00:00+00:00');
        $job = AuditExportJob::queue('exp-4', Scope::Tenant, 'org-1', 'u', null, new AuditExportFilter(), $now);
        $job->markRunning($now);
        $result = new AuditExportResult('b', 'm', 1, 1, 'h', [], 'u', $now);
        $job->markReady($result, $now, $now->modify('+1 day'));

        self::assertFalse($job->isPastRetention($now));
        self::assertTrue($job->isPastRetention($now->modify('+2 days')));
    }
}
