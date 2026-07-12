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

/**
 * GET /api/platform/audit/export — signed export of the platform chain (or a tenant's,
 * via ?scope=tenant&tenantId=…). Requires a 2FA step-up (cross-tenant data export).
 */
#[AsController]
#[RequiresAuth]
#[Requires2FA]
#[RequiresPermission('audit.export.any')]
#[Route('/api/platform/audit/export', name: 'audit.platform.export', methods: ['GET'])]
final class PlatformAuditExportController
{
    public function __construct(private readonly AuditAdminService $audit) {}

    public function __invoke(Request $request): JsonResponse
    {
        $scope    = Scope::tryFrom((string) $request->query->get('scope', 'platform')) ?? Scope::Platform;
        $tenantId = $scope === Scope::Tenant ? (string) $request->query->get('tenantId', '') : null;

        $from = $request->query->get('from');
        $to   = $request->query->get('to');

        $export = $this->audit->export(new AuditQuery(
            scope:    $scope,
            tenantId: $tenantId ?: null,
            from:     $from ? new \DateTimeImmutable((string) $from) : null,
            to:       $to ? new \DateTimeImmutable((string) $to) : null,
        ));

        return new JsonResponse(['ndjson' => $export->ndjson, 'manifest' => $export->manifest]);
    }
}
