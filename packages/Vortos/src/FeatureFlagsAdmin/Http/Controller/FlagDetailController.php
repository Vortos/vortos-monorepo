<?php

declare(strict_types=1);

namespace Vortos\FeatureFlagsAdmin\Http\Controller;

use Symfony\Component\Routing\Attribute\Route;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\FeatureFlags\Authz\Management\ManagementAuthzGateInterface;
use Vortos\FeatureFlags\Explain\EvaluationExplainer;
use Vortos\FeatureFlags\FlagContext;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\FeatureFlags\Guardrail\Storage\GuardrailPolicyStorageInterface;
use Vortos\FeatureFlags\Http\RateLimit\FlagRateLimitService;
use Vortos\FeatureFlags\ProjectContext;
use Vortos\FeatureFlags\ReadModel\FlagAuditLogRepositoryInterface;
use Vortos\FeatureFlags\ReadModel\FlagStateViewRepositoryInterface;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;
use Vortos\FeatureFlagsAdmin\Rendering\TwigRenderer;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\Exception\NotFoundException;
use Vortos\Http\Request;
use Vortos\Http\Response;

#[AsController]
final class FlagDetailController
{
    public function __construct(
        private readonly TwigRenderer $renderer,
        private readonly FlagStorageInterface $storage,
        private readonly FlagStateViewRepositoryInterface $stateView,
        private readonly FlagAuditLogRepositoryInterface $auditLog,
        private readonly ManagementAuthzGateInterface $authz,
        private readonly CurrentUserProvider $currentUser,
        private readonly FlagRateLimitService $rateLimit,
        private readonly FlagScopeContext $scopeContext,
        private readonly ProjectContext $projectContext,
        private readonly EvaluationExplainer $explainer,
        private readonly ?GuardrailPolicyStorageInterface $guardrailStorage = null,
    ) {}

    #[Route('/admin/flags/detail/{name}', name: 'vortos.admin.flags.detail', methods: ['GET'])]
    public function show(Request $request, string $name): Response
    {
        $this->authz->requirePermission('flags.read');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());

        $env = $request->query->get('env', 'production');
        $this->scopeContext->withEnvironment($env);

        $flag = $this->storage->findByName($name);
        if ($flag === null) {
            throw new NotFoundException("Flag '{$name}' not found.");
        }

        $stateView = $this->stateView->findByName($name, $env);
        $history = $this->auditLog->findByFlag($name, 10);

        $guardrails = [];
        if ($this->guardrailStorage !== null) {
            $allPolicies = $this->guardrailStorage->findEnabled(
                $this->projectContext->projectId() ?? 'default',
                $env,
            );
            $guardrails = array_filter($allPolicies, static fn($p) => $p->flagName === $name);
            $guardrails = array_values($guardrails);
        }

        $rulesJson = json_encode(
            array_map(static fn($r) => $r->toArray(), $flag->rules),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
        );

        return $this->renderer->render('flags/detail.html.twig', [
            'flag' => $flag,
            'state_view' => $stateView,
            'history' => $history,
            'guardrails' => $guardrails,
            'rules_json' => $rulesJson,
            'env' => $env,
            'environments' => ['production', 'staging', 'development', 'test'],
            'active_nav' => 'dashboard',
            'prefix' => '/admin/flags',
        ]);
    }

    #[Route('/admin/flags/detail/{name}/preview', name: 'vortos.admin.flags.preview', methods: ['POST'])]
    public function preview(Request $request, string $name): Response
    {
        $this->authz->requirePermission('flags.read');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());

        $env = $request->request->get('env', 'production');
        $this->scopeContext->withEnvironment($env);

        $flag = $this->storage->findByName($name);
        if ($flag === null) {
            throw new NotFoundException("Flag '{$name}' not found.");
        }

        $contextData = [];
        $userId = $request->request->get('preview_user_id', '');
        if ($userId !== '') {
            $contextData['userId'] = $userId;
        }
        $tenantId = $request->request->get('preview_tenant_id', '');
        if ($tenantId !== '') {
            $contextData['tenantId'] = $tenantId;
        }
        $attributes = $request->request->get('preview_attributes', '');
        if ($attributes !== '') {
            $decoded = json_decode($attributes, true);
            if (is_array($decoded)) {
                $contextData = array_merge($contextData, $decoded);
            }
        }

        $context = FlagContext::fromArray($contextData);
        $explanation = $this->explainer->explain($flag, $context);

        return $this->renderer->renderFragment('flags/_preview_result.html.twig', [
            'explanation' => $explanation,
            'flag' => $flag,
        ]);
    }
}
