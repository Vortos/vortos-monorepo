<?php

declare(strict_types=1);

namespace Vortos\Audit\Export;

use Psr\Clock\ClockInterface;
use Vortos\Audit\Enum\Scope;

/**
 * App-facing coordinator for async exports — the surface the HTTP controllers call. Keeps
 * controllers thin: enqueue a request, read a job's status, list a scope's jobs, and mint a
 * fresh presigned download URL on demand.
 *
 * Download URLs are minted per-request (short TTL) rather than stored, so a status response
 * never hands back a stale or already-expired link, and a link is only ever produced while the
 * artifact is genuinely downloadable (Ready + within its retention window).
 */
final class AuditExportService
{
    public function __construct(
        private readonly AuditExportEnqueuer         $enqueuer,
        private readonly AuditExportJobStoreInterface $jobs,
        private readonly AuditExportSinkInterface     $sink,
        private readonly ClockInterface               $clock,
        private readonly int                          $downloadUrlTtlSeconds = 900, // 15m
    ) {}

    public function request(
        Scope             $scope,
        ?string           $tenantId,
        string            $requestedByActorId,
        ?string           $requestedByLabel,
        AuditExportFilter $filter,
    ): AuditExportJob {
        return $this->enqueuer->enqueue($scope, $tenantId, $requestedByActorId, $requestedByLabel, $filter);
    }

    public function job(string $id): ?AuditExportJob
    {
        return $this->jobs->find($id);
    }

    /**
     * @return list<AuditExportJob>
     */
    public function list(Scope $scope, ?string $tenantId, int $limit = 25): array
    {
        return $this->jobs->listForScope($scope, $tenantId, $limit);
    }

    /**
     * A fresh, short-lived download URL for a Ready job, or null when the artifact is not
     * (or no longer) downloadable.
     */
    public function freshDownloadUrl(AuditExportJob $job): ?string
    {
        $now = $this->clock->now();
        if (!$job->status()->isDownloadable() || $job->bodyKey() === null || $job->isPastRetention($now)) {
            return null;
        }

        return $this->sink->temporaryDownloadUrl(
            $job->bodyKey(),
            $now->add(new \DateInterval('PT' . $this->downloadUrlTtlSeconds . 'S')),
        );
    }
}
