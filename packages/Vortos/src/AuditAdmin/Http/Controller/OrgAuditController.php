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
use Vortos\Tenant\TenantContext;

/**
 * GET /api/org/audit — the caller's own org audit trail, keyset-paginated.
 * Scope is forced to the caller's tenant (from TenantContext), so an org admin can never
 * read another org. Supports ?targetId= for a single member/resource's activity.
 */
#[AsController]
#[RequiresAuth]
#[RequiresPermission('audit.read.own')]
#[Route('/api/org/audit', name: 'audit.org.list', methods: ['GET'])]
final class OrgAuditController
{
    public function __construct(
        private readonly AuditAdminService $audit,
        private readonly TenantContext     $tenantContext,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $orgId = $this->tenantContext->requireTenantId();

        $query = new AuditQuery(
            scope:          Scope::Tenant,
            tenantId:       $orgId,
            actorId:        $request->query->get('actorId') ?: null,
            action:         $request->query->get('action') ?: null,
            minSensitivity: Sensitivity::tryFrom((string) $request->query->get('minSensitivity', '')),
            outcome:        Outcome::tryFrom((string) $request->query->get('outcome', '')),
            targetId:       $request->query->get('targetId') ?: null,
            cursor:         AuditCursor::decode((string) $request->query->get('cursor', '')),
            limit:          (int) $request->query->get('limit', 50),
            actionPrefix:   $request->query->get('actionPrefix') ?: null,
            search:         $request->query->get('search') ?: null,
        );

        $page = $this->audit->page($query);

        $body = [
            'records'    => array_map([AuditRecordPresenter::class, 'toArray'], $page->records),
            'nextCursor' => $page->nextCursor?->encode(),
        ];
        if ($request->query->getBoolean('withFacets')) {
            $body['facets'] = $this->audit->facets($query)->toArray();
        }

        return new JsonResponse($body);
    }
}
