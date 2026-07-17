<?php

declare(strict_types=1);

namespace Vortos\Audit\Export;

use Vortos\Audit\Enum\AuditExportStatus;
use Vortos\Audit\Enum\Scope;

/**
 * Test/dev in-memory {@see AuditExportJobStoreInterface}. NOT for production — jobs vanish on
 * process exit. Handy for handler/service unit tests and for exercising the flow without a DB.
 */
final class InMemoryExportJobStore implements AuditExportJobStoreInterface
{
    /** @var array<string, AuditExportJob> */
    public array $jobs = [];

    public function save(AuditExportJob $job): void
    {
        $this->jobs[$job->id] = $job;
    }

    public function find(string $id): ?AuditExportJob
    {
        return $this->jobs[$id] ?? null;
    }

    public function listForScope(Scope $scope, ?string $tenantId, int $limit = 25): array
    {
        $matched = array_filter(
            $this->jobs,
            static fn (AuditExportJob $j): bool => $j->scope === $scope && $j->tenantId === $tenantId,
        );
        $matched = array_values($matched);
        usort($matched, static fn (AuditExportJob $a, AuditExportJob $b): int => $b->createdAt <=> $a->createdAt);

        return array_slice($matched, 0, $limit);
    }

    public function findExpired(\DateTimeImmutable $now, int $limit = 100): array
    {
        $matched = array_filter(
            $this->jobs,
            static fn (AuditExportJob $j): bool => $j->status() === AuditExportStatus::Ready && $j->isPastRetention($now),
        );

        return array_slice(array_values($matched), 0, $limit);
    }
}
