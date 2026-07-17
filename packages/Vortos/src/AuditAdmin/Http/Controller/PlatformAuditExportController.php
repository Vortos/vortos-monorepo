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

/**
 * POST /api/platform/audit/export — enqueue an async signed export of the platform chain (or a
 * tenant's, via body/query scope=tenant&tenantId=…). Cross-tenant export requires the .any
 * permission and a 2FA step-up; it runs out of band and is polled via the status endpoint.
 */
#[AsController]
#[RequiresAuth]
#[Requires2FA]
#[RequiresPermission('audit.export.any')]
#[Route('/api/platform/audit/export', name: 'audit.platform.export', methods: ['POST'])]
final class PlatformAuditExportController
{
    public function __construct(
        private readonly AuditExportService  $exports,
        private readonly CurrentUserProvider $currentUser,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $body     = json_decode((string) $request->getContent(), true);
        $body     = \is_array($body) ? $body : [];
        $scope    = Scope::tryFrom((string) ($body['scope'] ?? $request->query->get('scope', 'platform'))) ?? Scope::Platform;
        $tenantId = $scope === Scope::Tenant
            ? (string) ($body['tenantId'] ?? $request->query->get('tenantId', ''))
            : null;

        if ($scope === Scope::Tenant && ($tenantId === null || $tenantId === '')) {
            return new JsonResponse(['error' => 'tenantId is required for a tenant-scoped export'], 422);
        }

        $identity = $this->currentUser->get();

        $job = $this->exports->request(
            scope:              $scope,
            tenantId:           $tenantId ?: null,
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
