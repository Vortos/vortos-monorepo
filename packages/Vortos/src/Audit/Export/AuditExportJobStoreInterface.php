<?php

declare(strict_types=1);

namespace Vortos\Audit\Export;

use Vortos\Audit\Enum\Scope;

/**
 * Persistence port for {@see AuditExportJob}. Narrow by design: create, load, save, and the
 * two list paths the consoles and the GC command need. Scope/tenant filtering lives here so
 * an org can only ever see its own export jobs.
 */
interface AuditExportJobStoreInterface
{
    public function save(AuditExportJob $job): void;

    public function find(string $id): ?AuditExportJob;

    /**
     * Recent jobs for one scope/tenant, newest first — the "your exports" list.
     *
     * @return list<AuditExportJob>
     */
    public function listForScope(Scope $scope, ?string $tenantId, int $limit = 25): array;

    /**
     * Ready jobs whose artifacts have passed their retention window — the GC worklist.
     *
     * @return list<AuditExportJob>
     */
    public function findExpired(\DateTimeImmutable $now, int $limit = 100): array;
}
