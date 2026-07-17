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
use Vortos\Http\Request;
use Vortos\Tenant\TenantContext;

/**
 * GET /api/org/audit/exports — the caller org's recent export jobs, newest first. Each row
 * carries a fresh signed download URL when its artifact is still downloadable.
 */
#[AsController]
#[RequiresAuth]
#[RequiresPermission('audit.export.own')]
#[Route('/api/org/audit/exports', name: 'audit.org.export.list', methods: ['GET'])]
final class OrgAuditExportListController
{
    public function __construct(
        private readonly AuditExportService $exports,
        private readonly TenantContext      $tenantContext,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $orgId = $this->tenantContext->requireTenantId();
        $limit = min(100, max(1, (int) $request->query->get('limit', 25)));

        $jobs = $this->exports->list(Scope::Tenant, $orgId, $limit);

        return new JsonResponse([
            'exports' => array_map(
                fn ($job): array => AuditExportJobPresenter::toArray($job, $this->exports->freshDownloadUrl($job)),
                $jobs,
            ),
        ]);
    }
}
