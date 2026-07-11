<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Http\Management;

use Symfony\Component\Routing\Attribute\Route;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\FeatureFlags\Authz\Management\ManagementAuthzGateInterface;
use Vortos\FeatureFlags\Explain\EvaluationExplainer;
use Vortos\FeatureFlags\FlagContext;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\FeatureFlags\Http\RateLimit\FlagRateLimitService;
use Vortos\FeatureFlags\Resolution\EffectiveFlagResolverInterface;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\JsonResponse;
use Vortos\Http\Request;

/**
 * Preview endpoint — evaluate an arbitrary context for a flag and return the full
 * explain trace (Block 19). Management-authz gated: the caller does NOT need to be
 * the previewed user.
 */
#[AsController]
final class FlagPreviewController
{
    public function __construct(
        private readonly FlagStorageInterface $storage,
        private readonly EvaluationExplainer $explainer,
        private readonly ?EffectiveFlagResolverInterface $resolver,
        private readonly ManagementAuthzGateInterface $authz,
        private readonly FlagRateLimitService $rateLimit,
        private readonly ManagementResponseFactory $response,
        private readonly CurrentUserProvider $currentUser,
        private readonly ?FlagScopeContext $scopeContext = null,
    ) {}

    private const ENVIRONMENTS = ['production', 'staging', 'development', 'test'];

    /** Scope evaluation to the requested environment so preview reflects that env's state. */
    private function applyEnv(Request $request): void
    {
        if ($this->scopeContext === null) {
            return;
        }
        $env = (string) $request->query->get('env', FlagScopeContext::ENV_PRODUCTION);
        $this->scopeContext->withEnvironment(in_array($env, self::ENVIRONMENTS, true) ? $env : FlagScopeContext::ENV_PRODUCTION);
    }

    #[Route('/api/management/v1/flags/{flagName}/preview', name: 'vortos.management.flags.preview', methods: ['POST'])]
    public function preview(Request $request, string $flagName): JsonResponse
    {
        $this->authz->requirePermission('flags.read.any');
        $actor = $this->currentUser->get();
        $this->rateLimit->checkManagement($actor->id());
        $this->applyEnv($request);

        $flag = $this->resolver !== null
            ? $this->resolver->resolve($flagName, new FlagContext())
            : $this->storage->findByName($flagName);

        if ($flag === null) {
            return new JsonResponse(['error' => 'flag not found'], 404);
        }

        $raw = $request->getContent();
        if (strlen($raw) > 16_384) {
            return new JsonResponse(['error' => 'payload too large'], 413);
        }

        try {
            $body = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'invalid json'], 400);
        }

        if (!is_array($body)) {
            return new JsonResponse(['error' => 'expected an object'], 400);
        }

        $context = new FlagContext(
            userId:     $body['userId'] ?? null,
            attributes: $body['attributes'] ?? [],
            trusted:    $body['trusted'] ?? [],
            untrusted:  $body['untrusted'] ?? [],
        );

        $detail = $this->explainer->explain($flag, $context);

        return $this->response->item($detail->toArray());
    }

    /**
     * Explain the current evaluation for all flags for an arbitrary context.
     */
    #[Route('/api/management/v1/flags/explain', name: 'vortos.management.flags.explain', methods: ['POST'])]
    public function explainAll(Request $request): JsonResponse
    {
        $this->authz->requirePermission('flags.read.any');
        $actor = $this->currentUser->get();
        $this->rateLimit->checkManagement($actor->id());

        $raw = $request->getContent();
        if (strlen($raw) > 16_384) {
            return new JsonResponse(['error' => 'payload too large'], 413);
        }

        try {
            $body = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'invalid json'], 400);
        }

        if (!is_array($body)) {
            return new JsonResponse(['error' => 'expected an object'], 400);
        }

        $context = new FlagContext(
            userId:     $body['userId'] ?? null,
            attributes: $body['attributes'] ?? [],
            trusted:    $body['trusted'] ?? [],
            untrusted:  $body['untrusted'] ?? [],
        );

        $this->applyEnv($request);

        $all = $this->resolver !== null
            ? $this->resolver->resolveAll($context)
            : $this->storage->findAll();

        $details = [];
        foreach ($all as $flag) {
            $details[] = $this->explainer->explain($flag, $context)->toArray();
        }

        return $this->response->list($details, null, count($details));
    }
}
