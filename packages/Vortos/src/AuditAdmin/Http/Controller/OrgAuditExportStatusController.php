<?php

declare(strict_types=1);

namespace Vortos\AuditAdmin\Http\Controller;

use Symfony\Component\Routing\Attribute\Route;
use Vortos\Audit\Enum\Scope;
use Vortos\Audit\Export\AuditExportService;
use Vortos\AuditAdmin\Http\Serializer\AuditExportJobPresenter;
use Vortos\Auth\Attribute\RequiresAuth;
use Vortos\Authorization\Attribute\RequiresPermission;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\JsonResponse;
use Vortos\Tenant\TenantContext;

/**
 * GET /api/org/audit/export/{jobId} — status of an export job, with a fresh signed download URL
 * once it is Ready. Cross-tenant isolation: a job whose tenant is not the caller's org is
 * reported as 404 (never leak another org's export existence).
 */
#[AsController]
#[RequiresAuth]
#[RequiresPermission('audit.export.own')]
#[Route('/api/org/audit/export/{jobId}', name: 'audit.org.export.status', methods: ['GET'])]
final class OrgAuditExportStatusController
{
    public function __construct(
        private readonly AuditExportService $exports,
        private readonly TenantContext      $tenantContext,
    ) {}

    public function __invoke(string $jobId): JsonResponse
    {
        $orgId = $this->tenantContext->requireTenantId();
        $job   = $this->exports->job($jobId);

        if ($job === null || $job->scope !== Scope::Tenant || $job->tenantId !== $orgId) {
            return new JsonResponse(['error' => 'Export not found'], 404);
        }

        return new JsonResponse(AuditExportJobPresenter::toArray($job, $this->exports->freshDownloadUrl($job)));
    }
}
