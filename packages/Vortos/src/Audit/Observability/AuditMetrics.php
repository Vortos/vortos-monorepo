<?php

declare(strict_types=1);

namespace Vortos\Audit\Observability;

use Vortos\Metrics\Contract\MetricsInterface;

/**
 * Thin, null-safe facade over the framework metrics port. When vortos-metrics is not
 * installed the injected port is null and every call is a no-op — the audit subsystem
 * never hard-depends on metrics being present.
 */
final class AuditMetrics
{
    public function __construct(private readonly ?MetricsInterface $metrics = null) {}

    public function ingested(string $scope): void
    {
        $this->metrics?->counter('vortos_audit_events_ingested_total', ['scope' => $scope])->increment();
    }

    public function duplicateSkipped(): void
    {
        $this->metrics?->counter('vortos_audit_ingest_duplicates_total')->increment();
    }

    public function ingestFailed(): void
    {
        $this->metrics?->counter('vortos_audit_ingest_failures_total')->increment();
    }

    public function verifyFailed(): void
    {
        $this->metrics?->counter('vortos_audit_verify_failures_total')->increment();
    }

    public function archived(int $count): void
    {
        if ($count > 0) {
            $this->metrics?->counter('vortos_audit_archived_total')->increment((float) $count);
        }
    }

    public function purged(int $count): void
    {
        if ($count > 0) {
            $this->metrics?->counter('vortos_audit_purged_total')->increment((float) $count);
        }
    }
}
