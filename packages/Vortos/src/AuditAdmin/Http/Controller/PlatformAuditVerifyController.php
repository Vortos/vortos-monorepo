<?php

declare(strict_types=1);

namespace Vortos\AuditAdmin\Http\Controller;

use Symfony\Component\Routing\Attribute\Route;
use Vortos\Audit\Admin\AuditAdminService;
use Vortos\Auth\Attribute\RequiresAuth;
use Vortos\Authorization\Attribute\RequiresPermission;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\JsonResponse;
use Vortos\Http\Request;

/**
 * POST /api/platform/audit/verify { "chainKey": "platform" | "tenant:{id}" }
 * Walks the chain's hash links + HMAC signatures and reports the first break, if any.
 */
#[AsController]
#[RequiresAuth]
#[RequiresPermission('audit.verify.any')]
#[Route('/api/platform/audit/verify', name: 'audit.platform.verify', methods: ['POST'])]
final class PlatformAuditVerifyController
{
    public function __construct(private readonly AuditAdminService $audit) {}

    public function __invoke(Request $request): JsonResponse
    {
        $body     = json_decode((string) $request->getContent(), true) ?: [];
        $chainKey = (string) (\is_array($body) ? ($body['chainKey'] ?? 'platform') : 'platform');

        $result = $this->audit->verifyChain($chainKey);

        return new JsonResponse([
            'valid'          => $result->valid,
            'verifiedCount'  => $result->verifiedCount,
            'brokenSequence' => $result->brokenSequence,
            'reason'         => $result->reason,
        ]);
    }
}
