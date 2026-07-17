<?php

declare(strict_types=1);

namespace Vortos\AuditAdmin\Http\Controller;

use Symfony\Component\Routing\Attribute\Route;
use Vortos\Audit\Enum\Scope;
use Vortos\Audit\Export\AuditExportService;
use Vortos\AuditAdmin\Http\AuditExportRequestParser;
use Vortos\AuditAdmin\Http\Serializer\AuditExportJobPresenter;
use Vortos\Auth\Attribute\RequiresAuth;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\TwoFactor\Attribute\Requires2FA;
use Vortos\Authorization\Attribute\RequiresPermission;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\JsonResponse;
use Vortos\Http\Request;
use Vortos\Tenant\TenantContext;

/**
 * POST /api/org/audit/export — enqueue an async signed export of the caller's org trail.
 *
 * Exporting the full trail is data-exfiltration-sensitive (and potentially very large), so it
 * requires a 2FA step-up and runs OUT OF BAND: this returns 202 with a job id; the export
 * consumer streams the trail to object storage and the caller polls the status endpoint (and/or
 * is notified) for the signed download URL. Scope is forced to the caller's tenant.
 */
#[AsController]
#[RequiresAuth]
#[Requires2FA]
#[RequiresPermission('audit.export.own')]
#[Route('/api/org/audit/export', name: 'audit.org.export', methods: ['POST'])]
final class OrgAuditExportController
{
    public function __construct(
        private readonly AuditExportService  $exports,
        private readonly CurrentUserProvider $currentUser,
        private readonly TenantContext       $tenantContext,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $orgId    = $this->tenantContext->requireTenantId();
        $identity = $this->currentUser->get();

        $job = $this->exports->request(
            scope:              Scope::Tenant,
            tenantId:           $orgId,
            requestedByActorId: $identity->id(),
            requestedByLabel:   $this->label($identity),
            filter:             AuditExportRequestParser::filter($request),
        );

        return new JsonResponse(AuditExportJobPresenter::toArray($job), 202);
    }

    private function label(object $identity): ?string
    {
        if (!method_exists($identity, 'getAttribute')) {
            return null;
        }
        $name  = $identity->getAttribute('name');
        $email = $identity->getAttribute('email');

        return \is_string($name) && $name !== '' ? $name
            : (\is_string($email) && $email !== '' ? $email : null);
    }
}
