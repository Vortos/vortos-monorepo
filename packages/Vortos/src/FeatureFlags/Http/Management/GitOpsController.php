<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Http\Management;

use Symfony\Component\Routing\Attribute\Route;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\FeatureFlags\Authz\Management\ManagementAuthzGateInterface;
use Vortos\FeatureFlags\GitOps\FlagDefinitionExporter;
use Vortos\FeatureFlags\GitOps\FlagDefinitionImporter;
use Vortos\FeatureFlags\GitOps\GitOpsDriftService;
use Vortos\FeatureFlags\Http\RateLimit\FlagRateLimitService;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\JsonResponse;
use Vortos\Http\Request;

/**
 * GitOps JSON: export the current flag definitions, import a declarative definition set
 * (dry-run by default), and detect drift between a declared file and runtime state.
 * Admin-gated; a straight JSON surface over the same services the CLI uses.
 */
#[AsController]
final class GitOpsController
{
    public function __construct(
        private readonly FlagDefinitionExporter $exporter,
        private readonly FlagDefinitionImporter $importer,
        private readonly GitOpsDriftService $drift,
        private readonly ManagementAuthzGateInterface $authz,
        private readonly CurrentUserProvider $currentUser,
        private readonly FlagRateLimitService $rateLimit,
        private readonly ManagementResponseFactory $response,
    ) {}

    #[Route('/api/management/v1/gitops/export', name: 'vortos.management.gitops.export', methods: ['GET'])]
    public function export(): JsonResponse
    {
        $this->guard();
        return $this->response->ok($this->exporter->export());
    }

    #[Route('/api/management/v1/gitops/import', name: 'vortos.management.gitops.import', methods: ['POST'])]
    public function import(Request $request): JsonResponse
    {
        $this->guard();
        $body   = $this->body($request);
        $dryRun = (bool) ($body['dryRun'] ?? true);
        $result = $this->importer->import($body, $dryRun, $this->currentUser->get()->id());

        return $this->response->ok(['dryRun' => $dryRun, 'hasChanges' => $result->hasChanges(), 'result' => $result->toArray()]);
    }

    #[Route('/api/management/v1/gitops/drift', name: 'vortos.management.gitops.drift', methods: ['POST'])]
    public function drift(Request $request): JsonResponse
    {
        $this->guard();
        $report = $this->drift->detect($this->body($request));

        return $this->response->ok(['hasDrift' => $report->hasDrift(), 'count' => $report->count(), 'entries' => $report->toArray()]);
    }

    private function guard(): void
    {
        $this->authz->requirePermission('flags.admin.any');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());
    }

    /** @return array{flags: list<array<string,mixed>>} */
    private function body(Request $request): array
    {
        $data = json_decode((string) $request->getContent(), true);
        if (!is_array($data) || !isset($data['flags']) || !is_array($data['flags'])) {
            return ['flags' => []];
        }
        return $data;
    }
}
