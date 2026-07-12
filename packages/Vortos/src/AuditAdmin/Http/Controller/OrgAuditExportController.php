<?php

declare(strict_types=1);

namespace Vortos\AuditAdmin\Http\Controller;

use Symfony\Component\Routing\Attribute\Route;
use Vortos\Audit\Admin\AuditAdminService;
use Vortos\Audit\Enum\Scope;
use Vortos\Audit\Query\AuditQuery;
use Vortos\Auth\Attribute\RequiresAuth;
use Vortos\Auth\TwoFactor\Attribute\Requires2FA;
use Vortos\Authorization\Attribute\RequiresPermission;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\JsonResponse;
use Vortos\Http\Request;
use Vortos\Tenant\TenantContext;

/**
 * GET /api/org/audit/export — signed NDJSON + manifest export of the caller's org trail.
 * Exporting the full trail is data-exfiltration-sensitive, so it requires a 2FA step-up.
 */
#[AsController]
#[RequiresAuth]
#[Requires2FA]
#[RequiresPermission('audit.export.own')]
#[Route('/api/org/audit/export', name: 'audit.org.export', methods: ['GET'])]
final class OrgAuditExportController
{
    public function __construct(
        private readonly AuditAdminService $audit,
        private readonly TenantContext     $tenantContext,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $orgId = $this->tenantContext->requireTenantId();

        $from = $request->query->get('from');
        $to   = $request->query->get('to');

        $export = $this->audit->export(new AuditQuery(
            scope:    Scope::Tenant,
            tenantId: $orgId,
            from:     $from ? new \DateTimeImmutable((string) $from) : null,
            to:       $to ? new \DateTimeImmutable((string) $to) : null,
        ));

        return new JsonResponse(['ndjson' => $export->ndjson, 'manifest' => $export->manifest]);
    }
}
