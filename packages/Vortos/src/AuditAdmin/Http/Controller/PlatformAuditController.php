<?php

declare(strict_types=1);

namespace Vortos\AuditAdmin\Http\Controller;

use Symfony\Component\Routing\Attribute\Route;
use Vortos\Audit\Admin\AuditAdminService;
use Vortos\Audit\Enum\Outcome;
use Vortos\Audit\Enum\Scope;
use Vortos\Audit\Enum\Sensitivity;
use Vortos\Audit\Query\AuditCursor;
use Vortos\Audit\Query\AuditQuery;
use Vortos\AuditAdmin\Http\Serializer\AuditRecordPresenter;
use Vortos\Auth\Attribute\RequiresAuth;
use Vortos\Authorization\Attribute\RequiresPermission;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\JsonResponse;
use Vortos\Http\Request;

/**
 * GET /api/platform/audit — cross-tenant audit console read. Defaults to the platform
 * (operator) chain; pass ?scope=tenant&tenantId=… to inspect a specific tenant.
 */
#[AsController]
#[RequiresAuth]
#[RequiresPermission('audit.read.any')]
#[Route('/api/platform/audit', name: 'audit.platform.list', methods: ['GET'])]
final class PlatformAuditController
{
    public function __construct(private readonly AuditAdminService $audit) {}

    public function __invoke(Request $request): JsonResponse
    {
        $scope    = Scope::tryFrom((string) $request->query->get('scope', 'platform')) ?? Scope::Platform;
        $tenantId = $scope === Scope::Tenant ? (string) $request->query->get('tenantId', '') : null;

        $page = $this->audit->page(new AuditQuery(
            scope:          $scope,
            tenantId:       $tenantId ?: null,
            actorId:        $request->query->get('actorId') ?: null,
            action:         $request->query->get('action') ?: null,
            minSensitivity: Sensitivity::tryFrom((string) $request->query->get('minSensitivity', '')),
            outcome:        Outcome::tryFrom((string) $request->query->get('outcome', '')),
            cursor:         AuditCursor::decode((string) $request->query->get('cursor', '')),
            limit:          (int) $request->query->get('limit', 50),
        ));

        return new JsonResponse([
            'records'    => array_map([AuditRecordPresenter::class, 'toArray'], $page->records),
            'nextCursor' => $page->nextCursor?->encode(),
        ]);
    }
}
