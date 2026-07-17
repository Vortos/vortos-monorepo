<?php

declare(strict_types=1);

namespace Vortos\AuditAdmin\Http\Controller;

use Symfony\Component\Routing\Attribute\Route;
use Vortos\Audit\Export\AuditExportService;
use Vortos\AuditAdmin\Http\Serializer\AuditExportJobPresenter;
use Vortos\Auth\Attribute\RequiresAuth;
use Vortos\Authorization\Attribute\RequiresPermission;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\JsonResponse;

/**
 * GET /api/platform/audit/export/{jobId} — status + fresh signed download URL for any export
 * job. Cross-tenant read, so it is gated on the .any export permission.
 */
#[AsController]
#[RequiresAuth]
#[RequiresPermission('audit.export.any')]
#[Route('/api/platform/audit/export/{jobId}', name: 'audit.platform.export.status', methods: ['GET'])]
final class PlatformAuditExportStatusController
{
    public function __construct(private readonly AuditExportService $exports) {}

    public function __invoke(string $jobId): JsonResponse
    {
        $job = $this->exports->job($jobId);

        if ($job === null) {
            return new JsonResponse(['error' => 'Export not found'], 404);
        }

        return new JsonResponse(AuditExportJobPresenter::toArray($job, $this->exports->freshDownloadUrl($job)));
    }
}
